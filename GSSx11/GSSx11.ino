/*
  GSSx11 — самостоятельный генератор на Si5351.
  GSSx10 и исходные библиотеки не изменяются.
*/

#define ENCODER_OPTIMIZE_INTERRUPTS

#include <Arduino.h>
#include <Encoder.h>
#include <string.h>

#include "i2c.h"
#include "si5351a.h"
#include "GyverOLED.h"
#include "Block_Ring_EEPROM.h"

// --------------------------- Аппаратная конфигурация -----------------------

static const uint8_t ENCODER_A_PIN = 2;
static const uint8_t ENCODER_B_PIN = 3;
static const uint8_t ENCODER_BUTTON_PIN = 4;
static const uint8_t ENCODER_COUNTS_PER_STEP = 4;

static const uint32_t MIN_FREQUENCY_HZ = 1000000UL;
static const uint32_t MAX_FREQUENCY_HZ = 200000000UL;
static const uint32_t DEFAULT_FREQUENCY_HZ = 7100000UL;
static const uint32_t XTAL_FREQUENCY_HZ = 32000000UL; // как начальное значение в GSSx10

static const uint32_t SAVE_DELAY_MS = 5000UL;
static const uint16_t BUTTON_DEBOUNCE_MS = 25;

// Сигнатура отдельная для GSSx11, не пересекается с другими проектами.
static const char EEPROM_SIGNATURE[] = "GSSx11";

// ------------------------------- Настройки --------------------------------

struct GeneratorSettings {
  uint32_t frequency;
  uint8_t stepIndex;
  uint8_t outputIndex;
};

// Для целевой AVR-платформы структура занимает ровно 6 байт.
static_assert(sizeof(GeneratorSettings) == 6, "Unexpected GeneratorSettings layout");

static const uint32_t STEP_VALUES_HZ[8] = {
  1UL, 10UL, 100UL, 1000UL, 10000UL, 100000UL, 1000000UL, 10000000UL
};

static const char * const STEP_LABELS[8] = {
  "1Hz", "10Hz", "100Hz", "1kHz",
  "10kHz", "100kHz", "1MHz", "10MHz"
};

enum UiField : uint8_t {
  FIELD_FREQUENCY = 0,
  FIELD_STEP = 1,
  FIELD_OUTPUT = 2
};

static GeneratorSettings settings;
static GeneratorSettings savedSettings;

static UiField activeField = FIELD_FREQUENCY;
static uint8_t stepIndexAtEntry = 0;

static bool hasUnsavedChanges = false;
static uint32_t lastActivityMillis = 0;

static long previousEncoderPosition = 0;

static bool buttonRawState = HIGH;
static bool buttonStableState = HIGH;
static uint32_t buttonDebounceMillis = 0;

Si5351 si;
Encoder myEnc(ENCODER_A_PIN, ENCODER_B_PIN);
GyverOLED<SSD1306_128x32, OLED_BUFFER> display;

// -------------------------------- Прототипы -------------------------------

static void fatalMemoryError(uint8_t errorCode);
static void loadDefaultSettings();
static bool settingsAreValid(const GeneratorSettings &value);
static bool settingsEqual(const GeneratorSettings &left, const GeneratorSettings &right);
static void copySettings(GeneratorSettings &destination, const GeneratorSettings &source);
static void updateUnsavedState();
static void renderDisplay();
static void formatFrequency(uint32_t frequency, char *buffer);
static void applyCurrentOutput();
static void showSaveFeedback();
static void handleButtonPress();
static void serviceEncoder();
static void serviceButton();
static void serviceSave();

// --------------------------------- Setup -----------------------------------

void setup() {
  pinMode(ENCODER_BUTTON_PIN, INPUT_PULLUP);

  display.init();
  display.clear();
  display.update();

  buttonRawState = digitalRead(ENCODER_BUTTON_PIN);
  buttonStableState = buttonRawState;
  buttonDebounceMillis = millis();

  // 1. Проверяем доступность внутренней EEPROM и читаем последнюю запись.
  uint8_t memoryStatus = Block_Ring_EEPROM.init(BLOCK_INTERNAL, EEPROM_SIGNATURE);
  if (memoryStatus != BLOCK_RING_OK) {
    fatalMemoryError(memoryStatus);
  }

  memoryStatus = Block_Ring_EEPROM.read(settings);

  if (memoryStatus == BLOCK_ERR_READ_NO_RECORD) {
    // Первый запуск: пустая EEPROM — штатная ситуация.
    loadDefaultSettings();

    memoryStatus = Block_Ring_EEPROM.write(settings);
    if (memoryStatus != BLOCK_RING_OK) {
      fatalMemoryError(memoryStatus);
    }

    copySettings(savedSettings, settings);
    showSaveFeedback();
  } else if (memoryStatus != BLOCK_RING_OK) {
    fatalMemoryError(memoryStatus);
  }

  // CRC записи уже проверена библиотекой; дополнительно проверяем диапазоны полей.
  if (!settingsAreValid(settings)) {
    fatalMemoryError(BLOCK_ERR_INVALID_ARGUMENT);
  }

  copySettings(savedSettings, settings);
  hasUnsavedChanges = false;

  // 2. Инициализируем I2C-драйвер Si5351 и сам синтезатор.
  // OLED уже поднял Wire; инициализируем низкоуровневый TWI перед доступом к Si5351.
  i2c_init(100000UL);

  si.setup(0, 0, 0); // 2 мА, как в GSSx10
  si.cload(SI5351_CRYSTAL_LOAD_10PF);
  si.set_xtal_freq(XTAL_FREQUENCY_HZ);
  si.update_freq(0);
  si.update_freq(1);

  applyCurrentOutput();

  previousEncoderPosition = myEnc.read() / ENCODER_COUNTS_PER_STEP;
  activeField = FIELD_FREQUENCY;
  lastActivityMillis = millis();

  renderDisplay();
}

// ---------------------------------- Loop -----------------------------------

void loop() {
  serviceEncoder();
  serviceButton();
  serviceSave();
}

// ------------------------------ Работа с EEPROM ----------------------------

static void loadDefaultSettings() {
  settings.frequency = DEFAULT_FREQUENCY_HZ;
  settings.stepIndex = 3;  // 1 кГц
  settings.outputIndex = 0; // CLK0
}

static bool settingsAreValid(const GeneratorSettings &value) {
  return value.frequency >= MIN_FREQUENCY_HZ &&
         value.frequency <= MAX_FREQUENCY_HZ &&
         value.stepIndex < 8 &&
         value.outputIndex < 3;
}

static bool settingsEqual(const GeneratorSettings &left, const GeneratorSettings &right) {
  return left.frequency == right.frequency &&
         left.stepIndex == right.stepIndex &&
         left.outputIndex == right.outputIndex;
}

static void copySettings(GeneratorSettings &destination, const GeneratorSettings &source) {
  destination.frequency = source.frequency;
  destination.stepIndex = source.stepIndex;
  destination.outputIndex = source.outputIndex;
}

static void updateUnsavedState() {
  hasUnsavedChanges = !settingsEqual(settings, savedSettings);
}

static void serviceSave() {
  if (!hasUnsavedChanges) return;
  if ((uint32_t)(millis() - lastActivityMillis) < SAVE_DELAY_MS) return;

  const uint8_t memoryStatus = Block_Ring_EEPROM.write(settings);
  if (memoryStatus != BLOCK_RING_OK) {
    fatalMemoryError(memoryStatus);
  }

  copySettings(savedSettings, settings);
  hasUnsavedChanges = false;
  showSaveFeedback();
}

// ------------------------------ Выходы Si5351 ------------------------------

static void applyCurrentOutput() {
  switch (settings.outputIndex) {
    case 0:
      si.set_freq(settings.frequency, 0, 0);
      break;

    case 1:
      si.set_freq(0, settings.frequency, 0);
      break;

    case 2:
      si.set_freq(0, 0, settings.frequency);
      break;

    default:
      fatalMemoryError(BLOCK_ERR_INVALID_ARGUMENT);
      break;
  }
}

// ------------------------------ Экран OLED ---------------------------------

static void formatFrequency(uint32_t frequency, char *buffer) {
  for (int8_t index = 8; index >= 0; --index) {
    buffer[index] = (char)('0' + (frequency % 10UL));
    frequency /= 10UL;
  }
  buffer[9] = '\0';
}

static void renderDisplay() {
  char frequencyText[10];
  formatFrequency(settings.frequency, frequencyText);

  display.clear();

  // Верхняя строка: девять цифр герц, крупный шрифт, по центру.
  display.setScale(2);
  display.setCursorXY(10, 0);
  display.invertText(activeField == FIELD_FREQUENCY);
  display.print(frequencyText);
  display.invertText(false);

  // Нижняя строка: CLK слева, шаг справа.
  display.setScale(1);
  display.setCursorXY(0, 24);
  display.invertText(activeField == FIELD_OUTPUT);
  display.print("CLK");
  display.print((uint16_t)settings.outputIndex);
  display.invertText(false);

  const char *stepLabel = STEP_LABELS[settings.stepIndex];
  const int stepLabelX = 128 - ((int)strlen(stepLabel) * 6);
  display.setCursorXY(stepLabelX, 24);
  display.invertText(activeField == FIELD_STEP);
  display.print(stepLabel);
  display.invertText(false);

  display.update();
}

static void showSaveFeedback() {
  display.invertDisplay(true);
  delay(150);
  display.invertDisplay(false);
}

static void fatalMemoryError(uint8_t errorCode) {
  display.invertText(false);
  display.invertDisplay(false);
  display.clear();
  display.setScale(1);
  display.setCursorXY(10, 12);
  display.print("[MEMORY ERROR #");
  display.print(errorCode);
  display.print("]");
  display.update();

  // Фатальная ошибка: оставляем сообщение на экране до перезапуска.
  while (true) {
    delay(1000);
  }
}

// ---------------------------- Энкодер и кнопка -----------------------------

static void handleButtonPress() {
  lastActivityMillis = millis();

  switch (activeField) {
    case FIELD_FREQUENCY:
      activeField = FIELD_STEP;
      stepIndexAtEntry = settings.stepIndex;
      break;

    case FIELD_STEP:
      // Если итоговый шаг отличается от шага при входе в поле — сразу к частоте.
      // Если не отличается — переходим к выбору выхода.
      activeField = (settings.stepIndex != stepIndexAtEntry)
                    ? FIELD_FREQUENCY
                    : FIELD_OUTPUT;
      break;

    case FIELD_OUTPUT:
    default:
      activeField = FIELD_FREQUENCY;
      break;
  }

  renderDisplay();
}

static void serviceEncoder() {
  const long currentPosition = myEnc.read() / ENCODER_COUNTS_PER_STEP;
  const long delta = currentPosition - previousEncoderPosition;
  if (delta == 0) return;

  previousEncoderPosition = currentPosition;
  lastActivityMillis = millis();

  bool redraw = false;

  switch (activeField) {
    case FIELD_FREQUENCY: {
      int64_t nextFrequency = (int64_t)settings.frequency +
                              ((int64_t)delta * (int64_t)STEP_VALUES_HZ[settings.stepIndex]);

      if (nextFrequency < (int64_t)MIN_FREQUENCY_HZ) {
        nextFrequency = MIN_FREQUENCY_HZ;
      } else if (nextFrequency > (int64_t)MAX_FREQUENCY_HZ) {
        nextFrequency = MAX_FREQUENCY_HZ;
      }

      if ((uint32_t)nextFrequency != settings.frequency) {
        settings.frequency = (uint32_t)nextFrequency;
        applyCurrentOutput();
        updateUnsavedState();
        redraw = true;
      }
      break;
    }

    case FIELD_STEP: {
      int16_t nextIndex = (int16_t)settings.stepIndex + (int16_t)(delta % 8L);
      while (nextIndex < 0) nextIndex += 8;
      while (nextIndex >= 8) nextIndex -= 8;

      if ((uint8_t)nextIndex != settings.stepIndex) {
        settings.stepIndex = (uint8_t)nextIndex;
        updateUnsavedState();
        redraw = true;
      }
      break;
    }

    case FIELD_OUTPUT: {
      int16_t nextIndex = (int16_t)settings.outputIndex + (int16_t)(delta % 3L);
      while (nextIndex < 0) nextIndex += 3;
      while (nextIndex >= 3) nextIndex -= 3;

      if ((uint8_t)nextIndex != settings.outputIndex) {
        settings.outputIndex = (uint8_t)nextIndex;
        // Сначала гасим прежний CLK, затем включаем новый: break-before-make.
        si.set_freq(0, 0, 0);
        applyCurrentOutput();
        updateUnsavedState();
        redraw = true;
      }
      break;
    }
  }

  if (redraw) renderDisplay();
}

static void serviceButton() {
  const uint32_t now = millis();
  const bool reading = digitalRead(ENCODER_BUTTON_PIN);

  if (reading != buttonRawState) {
    buttonRawState = reading;
    buttonDebounceMillis = now;
  }

  if ((uint32_t)(now - buttonDebounceMillis) >= BUTTON_DEBOUNCE_MS &&
      reading != buttonStableState) {
    buttonStableState = reading;
    if (buttonStableState == LOW) {
      handleButtonPress();
    }
  }
}
