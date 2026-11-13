/*
  GSSx11 — самостоятельный генератор на Si5351.
  GSSx10 и исходные библиотеки не изменяются.
*/

#define ENCODER_OPTIMIZE_INTERRUPTS

#include <Arduino.h>
#include <Encoder.h>
#include <EEPROM.h>
#include <string.h>
#include <stdio.h>

#include "i2c.h"
#include "si5351a.h"
#include "GyverOLED.h"
#include "Block_Ring_EEPROM.h"

// --------------------------- Пользовательские параметры --------------------

#define SAVE_DELAY 5000UL
#define ENGINEERING_MENU 1000UL

// Делитель энкодера для частоты и инженерной настройки кварца.
#define ENCODER_COUNTS_PER_STEP 4
// Отдельный делитель для шага, выхода, реверса и мощности.
#define ENCODER_MENU_COUNTS_PER_STEP 4

// --------------------------- Аппаратная конфигурация -----------------------

static const uint8_t ENCODER_A_PIN = 2;
static const uint8_t ENCODER_B_PIN = 3;
static const uint8_t ENCODER_BUTTON_PIN = 4;

static const uint32_t MIN_FREQUENCY_HZ = 1000000UL;
static const uint32_t MAX_FREQUENCY_HZ = 200000000UL;
static const uint32_t DEFAULT_FREQUENCY_HZ = 7100000UL;

static const uint16_t ENGINEERING_EEPROM_RESERVED = 16;

// Сигнатура отдельная для GSSx11, не пересекается с другими проектами.
static const char EEPROM_SIGNATURE[] = "GSSx11";

// ------------------------------- Настройки --------------------------------

struct GeneratorSettings {
  uint32_t frequency;
  uint8_t stepIndex;
  uint8_t outputIndex;
};

struct EngineeringSettings {
  bool reverseEncoder = false;
  uint32_t xtalFrequency = 32000000UL;
  uint8_t power = 0;
};

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
static EngineeringSettings engineeringSettings;

static UiField activeField = FIELD_FREQUENCY;
static uint8_t stepIndexAtEntry = 0;

static bool encoderReverse = false;
static bool engineeringMenuActive = false;
static uint8_t engineeringActiveItem = 0;

// Флаг фиксирует именно вращение энкодера, а не изменение значений настроек.
static bool encoderTouched = false;
static bool saveTimerActive = false;
static bool suppressFrequencyInvert = true;
static uint32_t lastEncoderActivityMillis = 0;

static long previousEncoderPosition = 0;

static bool knobup = true;
static uint32_t knobMillis = 0;

Si5351 si;
Encoder myEnc(ENCODER_A_PIN, ENCODER_B_PIN);
GyverOLED<SSD1306_128x32, OLED_BUFFER> display;

// -------------------------------- Прототипы -------------------------------

static void fatalMemoryError(uint8_t errorCode);
static void loadDefaultSettings();
static bool settingsAreValid(const GeneratorSettings &value);
static void loadEngineeringSettings();
static void saveEngineeringSettings();
static void markEncoderTouched();
static void renderDisplay();
static void renderEngineeringMenu();
static void formatFrequency(uint32_t frequency, char *buffer);
static void applyCurrentOutput();
static void showSaveFeedback();
static void handleShortButtonPress();
static void toggleEngineeringMenu();
static uint8_t encoderCountsPerStep();
static void syncEncoderPosition();
static void serviceEncoder();
static void serviceButton();
static void serviceSave();

// --------------------------------- Setup -----------------------------------

void setup() {
  pinMode(ENCODER_BUTTON_PIN, INPUT_PULLUP);

  // Сначала проверяем контрольную сумму инженерных настроек в начале EEPROM.
  // При несовпадении записываются значения по умолчанию.
  loadEngineeringSettings();
  encoderReverse = engineeringSettings.reverseEncoder;

  // Кольцевая память использует только область после инженерных настроек.
  uint8_t memoryStatus = Block_Ring_EEPROM.init(
    BLOCK_INTERNAL, EEPROM_SIGNATURE,
    ENGINEERING_EEPROM_RESERVED, E2END
  );
  if (memoryStatus != BLOCK_RING_OK) fatalMemoryError(memoryStatus);

  memoryStatus = Block_Ring_EEPROM.read(settings);
  if (memoryStatus == BLOCK_ERR_READ_NO_RECORD) {
    loadDefaultSettings();
  } else if (memoryStatus != BLOCK_RING_OK) {
    fatalMemoryError(memoryStatus);
  }

  if (!settingsAreValid(settings)) {
    fatalMemoryError(BLOCK_ERR_INVALID_ARGUMENT);
  }

  // Инициализируем I2C-драйвер Si5351 и сам синтезатор.
  i2c_init(400000UL);

  si.setup(0, 0, 0);
  si.cload(SI5351_CRYSTAL_LOAD_10PF);
  si.set_xtal_freq(engineeringSettings.xtalFrequency);
  si.set_power(settings.outputIndex, engineeringSettings.power);
  si.update_freq(0);
  si.update_freq(1);

  applyCurrentOutput();

  activeField = FIELD_FREQUENCY;
  syncEncoderPosition();
  delay(100);
  display.init();
  display.clear();
  display.update();
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
  settings.stepIndex = 3;   // 1 кГц
  settings.outputIndex = 0; // CLK0
}

static bool settingsAreValid(const GeneratorSettings &value) {
  return value.frequency >= MIN_FREQUENCY_HZ &&
         value.frequency <= MAX_FREQUENCY_HZ &&
         value.stepIndex < 8 &&
         value.outputIndex < 3;
}

static void saveEngineeringSettings() {
  uint16_t checksum = 0;
  const uint8_t *data = reinterpret_cast<const uint8_t *>(&engineeringSettings);

  for (uint8_t i = 0; i < sizeof(EngineeringSettings); ++i) {
    checksum += data[i];
  }

  EEPROM.put(2, engineeringSettings);
  EEPROM.put(0, checksum);
}

static void loadEngineeringSettings() {
  uint16_t storedChecksum = 0;
  uint16_t calculatedChecksum = 0;

  EEPROM.get(0, storedChecksum);

  // Контрольная сумма вычисляется непосредственно при чтении EEPROM.
  for (uint8_t i = 0; i < sizeof(EngineeringSettings); ++i) {
    calculatedChecksum += EEPROM.read(2 + i);
  }

  if (storedChecksum == calculatedChecksum) {
    EEPROM.get(2, engineeringSettings);
  } else {
    // При первой записи или повреждении данных используем значения по умолчанию.
    engineeringSettings = EngineeringSettings();
    saveEngineeringSettings();
  }
}

static void serviceSave() {
  if (!encoderTouched || !saveTimerActive || engineeringMenuActive) return;
  if ((uint32_t)(millis() - lastEncoderActivityMillis) < SAVE_DELAY) return;

  // Сохраняем оба набора настроек одной операцией обслуживания.
  const uint8_t memoryStatus = Block_Ring_EEPROM.write(settings);
  if (memoryStatus != BLOCK_RING_OK) fatalMemoryError(memoryStatus);

  saveEngineeringSettings();

  encoderTouched = false;
  saveTimerActive = false;
  activeField = FIELD_FREQUENCY;
  suppressFrequencyInvert = true;

  showSaveFeedback();
  renderDisplay();
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

display.setScale(2);
display.invertText(activeField == FIELD_FREQUENCY && !suppressFrequencyInvert);

// МГц
display.setCursorXY(4, 0);
display.print(frequencyText[0]);
display.print(frequencyText[1]);
display.print(frequencyText[2]);

// кГц
display.setCursorXY(46, 0);
display.print(frequencyText[3]);
display.print(frequencyText[4]);
display.print(frequencyText[5]);

// Гц
display.setCursorXY(87, 0);
display.print(frequencyText[6]);
display.print(frequencyText[7]);
display.print(frequencyText[8]);

display.invertText(false);

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

static void renderEngineeringMenu() {
  char line[20];

  display.clear();
  display.setScale(1);

  display.setCursorXY(0, 0);
  display.invertText(engineeringActiveItem == 0);
  display.print("REV_ENC=");
  display.print(engineeringSettings.reverseEncoder ? "YES" : "NO");
  display.invertText(false);

  display.setCursorXY(0, 8);
  display.invertText(engineeringActiveItem == 1);
  snprintf(line, sizeof(line), "XTL=%lu", (unsigned long)engineeringSettings.xtalFrequency);
  display.print(line);
  display.invertText(false);

  display.setCursorXY(0, 16);
  display.invertText(engineeringActiveItem == 2);
  snprintf(line, sizeof(line), "PWR=%u", (unsigned int)engineeringSettings.power);
  display.print(line);
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

  while (true) delay(1000);
}

// ---------------------------- Энкодер и кнопка -----------------------------

static void markEncoderTouched() {
  encoderTouched = true;
  if (!engineeringMenuActive) {
    lastEncoderActivityMillis = millis();
    saveTimerActive = true;
  }
  suppressFrequencyInvert = false;
}

static void handleShortButtonPress() {
  if (engineeringMenuActive) {
    engineeringActiveItem++;
    if (engineeringActiveItem >= 3) engineeringActiveItem = 0;
    syncEncoderPosition();
    renderEngineeringMenu();
    return;
  }

  switch (activeField) {
    case FIELD_FREQUENCY:
      activeField = FIELD_STEP;
      stepIndexAtEntry = settings.stepIndex;
      break;

    case FIELD_STEP:
      activeField = (settings.stepIndex != stepIndexAtEntry)
                    ? FIELD_FREQUENCY
                    : FIELD_OUTPUT;
      break;

    case FIELD_OUTPUT:
    default:
      activeField = FIELD_FREQUENCY;
      break;
  }

  syncEncoderPosition();
  renderDisplay();
}

static void toggleEngineeringMenu() {
  if (!engineeringMenuActive) {
    engineeringMenuActive = true;
    saveTimerActive = false;
    engineeringActiveItem = 0;
    syncEncoderPosition();
    renderEngineeringMenu();
    return;
  }

  engineeringMenuActive = false;
  activeField = FIELD_FREQUENCY;
  syncEncoderPosition();

  // Если энкодер вращали в инженерном меню, отсчёт начинается после выхода.
  if (encoderTouched) {
    lastEncoderActivityMillis = millis();
    saveTimerActive = true;
  } else {
    saveTimerActive = false;
  }

  suppressFrequencyInvert = false;
  renderDisplay();
}

static uint8_t encoderCountsPerStep() {
  if (engineeringMenuActive) {
    return engineeringActiveItem == 1
           ? ENCODER_COUNTS_PER_STEP
           : ENCODER_MENU_COUNTS_PER_STEP;
  }

  return activeField == FIELD_FREQUENCY
         ? ENCODER_COUNTS_PER_STEP
         : ENCODER_MENU_COUNTS_PER_STEP;
}

static void syncEncoderPosition() {
  previousEncoderPosition = myEnc.read() / encoderCountsPerStep();
}

static void serviceEncoder() {
  const long currentPosition = myEnc.read() / encoderCountsPerStep();
  long delta = currentPosition - previousEncoderPosition;
  if (delta == 0) return;

  previousEncoderPosition = currentPosition;
  if (encoderReverse) delta = -delta;

  const bool restoreHighlight = suppressFrequencyInvert;
  markEncoderTouched();

  if (engineeringMenuActive) {
    switch (engineeringActiveItem) {
      case 0:
        if ((delta % 2L) != 0) {
          engineeringSettings.reverseEncoder = !engineeringSettings.reverseEncoder;
        }
        break;

      case 1: {
        int64_t nextValue = (int64_t)engineeringSettings.xtalFrequency +
                            ((int64_t)delta * (int64_t)STEP_VALUES_HZ[settings.stepIndex]);
        if (nextValue < 1) nextValue = 1;
        if (nextValue > 0xFFFFFFFFLL) nextValue = 0xFFFFFFFFLL;

        if ((uint32_t)nextValue != engineeringSettings.xtalFrequency) {
          engineeringSettings.xtalFrequency = (uint32_t)nextValue;
          si.set_xtal_freq(engineeringSettings.xtalFrequency);
          // Пересчитываем текущий выход с новой опорной частотой.
          si.set_freq(0, 0, 0);
          applyCurrentOutput();
        }
        break;
      }

      case 2: {
        int16_t nextPower = (int16_t)engineeringSettings.power + (int16_t)(delta % 4L);
        while (nextPower < 0) nextPower += 4;
        while (nextPower >= 4) nextPower -= 4;

        if ((uint8_t)nextPower != engineeringSettings.power) {
          engineeringSettings.power = (uint8_t)nextPower;
          si.set_power(settings.outputIndex, engineeringSettings.power);
          applyCurrentOutput();
        }
        break;
      }
    }

    renderEngineeringMenu();
    return;
  }

  bool redraw = restoreHighlight;

  switch (activeField) {
    case FIELD_FREQUENCY: {
      const int64_t step = STEP_VALUES_HZ[settings.stepIndex];
      int64_t nextFrequency = settings.frequency;
      const int64_t remainder = nextFrequency % step;

      // Сначала доводим частоту до границы шага в сторону вращения.
      // Если частота уже кратна шагу, выполняем полный шаг.
      if (delta > 0) {
        nextFrequency += remainder ? (step - remainder) : step;
      } else {
        nextFrequency -= remainder ? remainder : step;
      }

      if (nextFrequency < MIN_FREQUENCY_HZ) {
        nextFrequency = MIN_FREQUENCY_HZ;
      } else if (nextFrequency > MAX_FREQUENCY_HZ) {
        nextFrequency = MAX_FREQUENCY_HZ;
      }

      if ((uint32_t)nextFrequency != settings.frequency) {
        settings.frequency = (uint32_t)nextFrequency;
        applyCurrentOutput();
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
        si.set_freq(0, 0, 0);
        si.set_power(settings.outputIndex, engineeringSettings.power);
        applyCurrentOutput();
        redraw = true;
      }
      break;
    }
  }

  if (redraw) renderDisplay();
}

static void serviceButton() {
  const bool knobdown = digitalRead(ENCODER_BUTTON_PIN);

  if (!knobdown && knobup) {
    knobup = false;
    knobMillis = millis();
  }

  if (knobdown && !knobup) {
    knobup = true;
    const uint32_t knobupMillis = millis();
    const uint32_t pressDuration = (uint32_t)(knobupMillis - knobMillis);

    if (pressDuration >= ENGINEERING_MENU) {
      toggleEngineeringMenu();
    } else if (pressDuration > 100UL) {
      handleShortButtonPress();
    }

    syncEncoderPosition();
  }
}
