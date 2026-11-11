// 8.04.2019, Arduino IDE v1.8.9, LZ2WSG, KN34PC
// Si5351 VFO CLK0, OLED 0.91" 128x32 display, S-meter          for arbalet-1k
//---------------------------------------------------------------------------------------------------------
#include <EEPROM.h>
#include "src/Rotary.h"              // http://www.buxtronix.net/2011/10/rotary-encoders-done-properly.html
#include "src/si5351.h"              // https://github.com/etherkit/Si5351Arduino, v2.1.0
#include "src/SSD1306AsciiAvrI2c.h"  // https://github.com/greiman/SSD1306Ascii

#define DEFAULT_RX    4000000        // частота при включении, если EEPROM пуста/повреждена
#define BAND_L        1500000        // начало диапазона
#define BAND_H        8500000        // конец диапазона
#define IF_FREQ       62500000       // частота ПЧ
#define IF2_LSB_FREQ  2496500        // вторая ПЧ, LSB — 2496,5 кГц
#define IF2_USB_FREQ  2500000        // вторая ПЧ, USB — 2500 кГц
#define LSB_USB_PIN   A3             // пин выбора LSB/USB
#define SW_STEP       A0             // выбор шага
#define PIN_SM        A1             // вход S-метра
#define PIN_RX_TX     A2             // RX/TX педаль

// Параметры кольцевого журнала настроек в EEPROM.
#define EEPROM_RECORD_ID       0xA7  // идентификатор записи Si5351 VFO
#define EEPROM_RECORD_SIZE    8      // ID + номер + частота(3) + шаг + CRC16(2)
#define EEPROM_ROLLBACK_DEPTH 3      // сколько последних записей проверять, включая самую новую
#define EEPROM_SAVE_DELAY     5000UL // сохранять после 5 секунд без изменений

#if EEPROM_ROLLBACK_DEPTH < 1
#error EEPROM_ROLLBACK_DEPTH must be at least 1
#endif

//#define BAND1         4              // ДПФ 1,5...3 мгц
//#define BAND2         5              // ДПФ 3...4 мгц
//#define BAND3         6              // ДПФ 4...6 мгц
//#define BAND4         7              // ДПФ 6...8,5 мгц

#define PR1           4              // "провод 1"
#define PR2           5              // "провод 2"
#define PR3           6              // "провод 3"

#define ENC_INC_1     100            // шаг 100 гц
#define ENC_INC_2     1000           // шаг 1 кгц
#define ENC_INC_3     10000          // шаг 10 кгц
#define ENC_INC_4     100000         // шаг 100 кгц

#define OLED_I2C_ADDR 0x3C           // OLED I2C адрес

Rotary r = Rotary(2, 3);
Si5351 si5351(0x60);                 // Si5351 I2C адрес
SSD1306AsciiAvrI2c oled;

uint32_t rx;
uint32_t enc_inc;
int8_t enc_dir = 0;                  // -1 DIR_CCW, 0 DIR_NONE, 1 DIR_CCW
bool ch_flag = true;
uint16_t val_sm = 0;
uint16_t val_sm_old = 1;
bool rx_tx;
bool rx_tx_old;
bool IF2_PIN_STATUS;
bool IF2_PIN_PREV;                   // статус предыдущего состояния LSB/USB пина
uint32_t IF2_TIMER = 0;              // таймер опроса LSB/USB

// Состояние кольцевого журнала EEPROM.
uint16_t eeprom_slot_count = 0;
uint16_t eeprom_next_slot = 0;
uint8_t eeprom_next_sequence = 0;
bool settings_dirty = false;
uint32_t settings_change_time = 0;

// Явные прототипы функций основного VFO.
void rotary_encoder();
void si5351_set_freq(uint32_t frequency);
void set_inc();
void show_inc();
void show_freq(uint32_t sf_rx);
void show_state();
void show_sm();
uint8_t button_state(uint8_t pin_bt);
//---------------------------------------------------------------------------------------------------------
// CRC-16/CCITT-FALSE: полином 0x1021, начальное значение 0xFFFF.
uint16_t eeprom_crc16(const uint8_t *data, uint8_t length) {
  uint16_t crc = 0xFFFF;

  for (uint8_t i = 0; i < length; i++) {
    crc ^= (uint16_t)data[i] << 8;
    for (uint8_t bit = 0; bit < 8; bit++) {
      if (crc & 0x8000)
        crc = (crc << 1) ^ 0x1021;
      else
        crc <<= 1;
    }
  }
  return crc;
}
//---------------------------------------------------------------------------------------------------------
// Код шага в EEPROM: 0=100 Гц, 1=1 кГц, 2=10 кГц, 3=100 кГц.
uint8_t eeprom_step_to_code(uint32_t step) {
  if (step == ENC_INC_1) return 0;
  if (step == ENC_INC_2) return 1;
  if (step == ENC_INC_3) return 2;
  if (step == ENC_INC_4) return 3;
  return 0xFF;
}

uint32_t eeprom_code_to_step(uint8_t code) {
  switch (code) {
    case 0: return ENC_INC_1;
    case 1: return ENC_INC_2;
    case 2: return ENC_INC_3;
    case 3: return ENC_INC_4;
    default: return 0;
  }
}
//---------------------------------------------------------------------------------------------------------
void eeprom_read_record(uint16_t slot, uint8_t data[EEPROM_RECORD_SIZE]) {
  uint16_t address = slot * EEPROM_RECORD_SIZE;
  for (uint8_t i = 0; i < EEPROM_RECORD_SIZE; i++)
    data[i] = EEPROM.read(address + i);
}
//---------------------------------------------------------------------------------------------------------
// ID-only запись означает, что питание могло пропасть сразу после начала записи.
// Она не участвует в поиске последовательности: указатель всё равно укажет на этот слот.
bool eeprom_record_started(const uint8_t data[EEPROM_RECORD_SIZE]) {
  if (data[0] != EEPROM_RECORD_ID)
    return false;

  for (uint8_t i = 1; i < EEPROM_RECORD_SIZE; i++) {
    if (data[i] != 0xFF)
      return true;
  }
  return false;
}
//---------------------------------------------------------------------------------------------------------
// Проверка случая, когда питание пропало сразу после записи одного только ID.
bool eeprom_record_id_only(uint16_t slot) {
  uint8_t data[EEPROM_RECORD_SIZE];
  eeprom_read_record(slot, data);

  if (data[0] != EEPROM_RECORD_ID)
    return false;

  for (uint8_t i = 1; i < EEPROM_RECORD_SIZE; i++) {
    if (data[i] != 0xFF)
      return false;
  }
  return true;
}
//---------------------------------------------------------------------------------------------------------
bool eeprom_record_valid(const uint8_t data[EEPROM_RECORD_SIZE], uint32_t &frequency, uint32_t &step) {
  if (data[0] != EEPROM_RECORD_ID)
    return false;

  uint16_t stored_crc = (uint16_t)data[6] | ((uint16_t)data[7] << 8);
  if (eeprom_crc16(data, 6) != stored_crc)
    return false;

  frequency = (uint32_t)data[2]
            | ((uint32_t)data[3] << 8)
            | ((uint32_t)data[4] << 16);
  step = eeprom_code_to_step(data[5]);

  if (frequency < BAND_L || frequency > BAND_H || step == 0)
    return false;

  return true;
}
//---------------------------------------------------------------------------------------------------------
// Сравнение 8-битных номеров с учётом переполнения 255 -> 0.
// Для 128 слотов разница между самой старой и самой новой записью не превышает 127.
bool eeprom_sequence_newer(uint8_t a, uint8_t b) {
  uint8_t difference = (uint8_t)(a - b);
  return (difference != 0 && difference < 128);
}
//---------------------------------------------------------------------------------------------------------
bool eeprom_find_latest(uint16_t &latest_slot, uint8_t &latest_sequence) {
  bool found = false;
  uint8_t data[EEPROM_RECORD_SIZE];

  for (uint16_t slot = 0; slot < eeprom_slot_count; slot++) {
    eeprom_read_record(slot, data);
    if (!eeprom_record_started(data))
      continue;

    if (!found || eeprom_sequence_newer(data[1], latest_sequence)) {
      latest_slot = slot;
      latest_sequence = data[1];
      found = true;
    }
  }
  return found;
}
//---------------------------------------------------------------------------------------------------------
// Поиск ближайшей предыдущей записи. CRC здесь специально не проверяется:
// повреждённая запись тоже занимает своё место в хронологии и глубине отката.
bool eeprom_find_previous(uint16_t current_slot, uint8_t current_sequence,
                          uint16_t &previous_slot, uint8_t &previous_sequence) {
  bool found = false;
  uint8_t best_distance = 128;
  uint8_t data[EEPROM_RECORD_SIZE];

  for (uint16_t slot = 0; slot < eeprom_slot_count; slot++) {
    if (slot == current_slot)
      continue;

    eeprom_read_record(slot, data);
    if (!eeprom_record_started(data))
      continue;

    uint8_t distance = (uint8_t)(current_sequence - data[1]);
    if (distance > 0 && distance < best_distance) {
      best_distance = distance;
      previous_slot = slot;
      previous_sequence = data[1];
      found = true;
    }
  }
  return found;
}
//---------------------------------------------------------------------------------------------------------
void eeprom_load_settings() {
  uint16_t latest_slot = 0;
  uint8_t latest_sequence = 0;
  uint8_t data[EEPROM_RECORD_SIZE];

  eeprom_slot_count = EEPROM.length() / EEPROM_RECORD_SIZE;

  // Однобайтовый номер однозначно упорядочивает до 128 последовательных слотов.
  // Для ATmega328P это все 1024 байта EEPROM: 128 записей по 8 байт.
  if (eeprom_slot_count > 128)
    eeprom_slot_count = 128;

  if (eeprom_slot_count == 0) {
    eeprom_next_slot = 0;
    eeprom_next_sequence = 0;
    return;
  }

  if (!eeprom_find_latest(latest_slot, latest_sequence)) {
    // Первая в жизни запись могла прерваться сразу после записи ID в слот 0.
    eeprom_next_slot = 0;
    eeprom_next_sequence = 0;
    return;
  }

  // Обычно проверяем самую новую запись. Если питание пропало сразу после ID
  // в следующем слоте, считаем этот слот начатой последней записью с ожидаемым
  // номером. Так он тоже занимает одну позицию глубины отката.
  uint16_t candidate_slot = latest_slot;
  uint8_t candidate_sequence = latest_sequence;
  uint16_t next_slot = (latest_slot + 1) % eeprom_slot_count;

  if (eeprom_record_id_only(next_slot)) {
    candidate_slot = next_slot;
    candidate_sequence = (uint8_t)(latest_sequence + 1);
    eeprom_next_slot = next_slot;       // при следующем сохранении повторим этот слот
    eeprom_next_sequence = candidate_sequence;
  }
  else {
    eeprom_next_slot = next_slot;
    eeprom_next_sequence = (uint8_t)(latest_sequence + 1);
  }
  uint16_t depth = EEPROM_ROLLBACK_DEPTH;
  if (depth > eeprom_slot_count)
    depth = eeprom_slot_count;

  for (uint16_t attempt = 0; attempt < depth; attempt++) {
    eeprom_read_record(candidate_slot, data);

    uint32_t saved_frequency;
    uint32_t saved_step;
    if (eeprom_record_valid(data, saved_frequency, saved_step)) {
      rx = saved_frequency;
      enc_inc = saved_step;
      return;
    }

    uint16_t previous_slot;
    uint8_t previous_sequence;
    if (!eeprom_find_previous(candidate_slot, candidate_sequence,
                              previous_slot, previous_sequence))
      break;

    candidate_slot = previous_slot;
    candidate_sequence = previous_sequence;
  }
  // Если подходящей записи нет, остаются значения по умолчанию из setup().
}
//---------------------------------------------------------------------------------------------------------
void eeprom_save_settings() {
  if (eeprom_slot_count == 0)
    return;

  uint8_t data[EEPROM_RECORD_SIZE];
  uint32_t frequency = rx;
  uint8_t step_code = eeprom_step_to_code(enc_inc);

  if (step_code == 0xFF || frequency < BAND_L || frequency > BAND_H)
    return;

  data[0] = EEPROM_RECORD_ID;
  data[1] = eeprom_next_sequence;
  data[2] = (uint8_t)(frequency & 0xFF);
  data[3] = (uint8_t)((frequency >> 8) & 0xFF);
  data[4] = (uint8_t)((frequency >> 16) & 0xFF);
  data[5] = step_code;

  uint16_t crc = eeprom_crc16(data, 6);
  data[6] = (uint8_t)(crc & 0xFF);
  data[7] = (uint8_t)(crc >> 8);

  uint16_t address = eeprom_next_slot * EEPROM_RECORD_SIZE;

  // ID пишется первым, чтобы отличать начатую запись от чужих данных.
  // CRC пишется последним: при обрыве питания незавершённая запись не пройдёт проверку.
  EEPROM.update(address + 0, data[0]);
  EEPROM.update(address + 1, data[1]);
  EEPROM.update(address + 2, data[2]);
  EEPROM.update(address + 3, data[3]);
  EEPROM.update(address + 4, data[4]);
  EEPROM.update(address + 5, data[5]);
  EEPROM.update(address + 6, data[6]);
  EEPROM.update(address + 7, data[7]);

  eeprom_next_slot++;
  if (eeprom_next_slot >= eeprom_slot_count)
    eeprom_next_slot = 0;
  eeprom_next_sequence++;
}
//---------------------------------------------------------------------------------------------------------
void settings_mark_changed() {
  settings_dirty = true;
  settings_change_time = millis();
}
//---------------------------------------------------------------------------------------------------------
void eeprom_save_if_idle() {
  if (settings_dirty && (uint32_t)(millis() - settings_change_time) >= EEPROM_SAVE_DELAY) {
    eeprom_save_settings();
    settings_dirty = false;
  }
}
//---------------------------------------------------------------------------------------------------------
void setup() {
  pinMode(SW_STEP, INPUT_PULLUP);        // вход с инверсией (включается землей)
  pinMode(PIN_RX_TX, INPUT_PULLUP);      // вход с инверсией (включается землей)
  
  pinMode(LSB_USB_PIN, INPUT_PULLUP);    // выбор LSB/USB с внутренней подтяжкой
  
  //pinMode(BAND1, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  //pinMode(BAND2, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  //pinMode(BAND3, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  //pinMode(BAND4, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  
  pinMode(PR1, OUTPUT);                  // при управлении ДПФ через 564кп2,"1"-5В, "0"-земля
  pinMode(PR2, OUTPUT);                  // при управлении ДПФ через 564кп2,"1"-5В, "0"-земля
  pinMode(PR3, OUTPUT);                  // при управлении ДПФ через 564кп2,"1"-5В, "0"-земля
  
  analogReference(INTERNAL);             // ADC reference voltage 5,0V or 1,1V (S-meter max IN voltage) чувствительность S-метра

  attachInterrupt(0, rotary_encoder, CHANGE);
  attachInterrupt(1, rotary_encoder, CHANGE);

  si5351.init(SI5351_CRYSTAL_LOAD_8PF, 0, 26430);           // crystal 25.000 MHz, последние цифры - коррекция частоты кварца
  //si5351.drive_strength(SI5351_CLK0,SI5351_DRIVE_4MA);    // регулировка выхода CLK0, регулируется током 2,4,6,8 МА ???
  si5351.drive_strength(SI5351_CLK1,SI5351_DRIVE_4MA);    // регулировка выхода CLK1, регулируется током 2,4,6,8 МА ???
  si5351.drive_strength(SI5351_CLK2,SI5351_DRIVE_4MA);    // регулировка выхода CLK2, регулируется током 2,4,6,8 МА ???
  
  IF2_PIN_PREV = !digitalRead(LSB_USB_PIN);               // гарантируем первую настройку CLK0
  
  si5351.set_freq(IF_FREQ*SI5351_FREQ_MULT,SI5351_CLK1);  // частота на выходе 1
  oled.begin(&Adafruit128x32, OLED_I2C_ADDR);

  // Сначала устанавливаем значения по умолчанию, затем пробуем восстановить EEPROM.
  rx = DEFAULT_RX;
  enc_inc = ENC_INC_4;
  eeprom_load_settings();
  
  rx_tx = digitalRead(PIN_RX_TX);
  rx_tx_old = ! rx_tx;
  show_state();

  show_inc();            // убираем надпись шага с дисплея
}
//---------------------------------------------------------------------------------------------------------
void IF2_SET() {
  if (millis() - IF2_TIMER >= 100) {
    IF2_PIN_STATUS = digitalRead(LSB_USB_PIN);

    if (IF2_PIN_STATUS != IF2_PIN_PREV) {
      if (IF2_PIN_STATUS) {
        si5351.set_freq(IF2_LSB_FREQ * SI5351_FREQ_MULT, SI5351_CLK0);
        oled.setFont(labels_UL);
        oled.setCursor(18, 3);
        oled.print('L');
        //oled.print('U');
        ch_flag = true;
      }
      else {
        si5351.set_freq(IF2_USB_FREQ * SI5351_FREQ_MULT, SI5351_CLK0);
        oled.setFont(labels_UL);
        oled.setCursor(18, 3);
        //oled.print('L');
        oled.print('U');
        ch_flag = true;
      }
    }

    IF2_PIN_PREV = IF2_PIN_STATUS;
    IF2_TIMER = millis();
  }
}
//---------------------------------------------------------------------------------------------------------
void loop() {
  IF2_SET();

  if (ch_flag) {
    ch_flag = false;
    uint32_t old_rx = rx;
    rx += enc_dir * enc_inc;
    enc_dir = 0;
    rx = constrain(rx, BAND_L, BAND_H);
    if (rx != old_rx)
      settings_mark_changed();
    //si5351_set_freq(rx - IF_FREQ);      // неверно !!!???                           // 
    // частота гетеродина на выходе si5351
    if (IF2_PIN_STATUS) 
    {
      si5351_set_freq(rx + IF_FREQ + IF2_LSB_FREQ);      // гетеродин "вверх" для LSB                         
    }
    else 
    {
      si5351_set_freq(rx + IF_FREQ + IF2_USB_FREQ);      // гетеродин "вверх" для USB 
    }
    //si5351_set_freq(IF_FREQ - rx);      // гетеродин "вниз" если Fin ниже IF ???    // 
    
  //if ((rx >1500000) & (rx < 3000000))   // при управлении ДПФ напрямую
     //{
        //digitalWrite(BAND1, 1);
        //digitalWrite(BAND2, 0);
        //digitalWrite(BAND3, 0);
        //digitalWrite(BAND4, 0);
      //}

  //if ((rx >2999999) & (rx < 4000000))   //при управлении ДПФ напрямую
      //{
        //digitalWrite(BAND1, 0);
        //digitalWrite(BAND2, 1);
        //digitalWrite(BAND3, 0);
        //digitalWrite(BAND4, 0);
      //}

  //if ((rx >3999999) & (rx < 6000000))   //при управлении ДПФ напрямую
     //{
        //digitalWrite(BAND1, 0);
        //digitalWrite(BAND2, 0);
        //digitalWrite(BAND3, 1);
        //digitalWrite(BAND4, 0);
      //}

  //if ((rx >5999999) & (rx < 8000001))   //при управлении ДПФ напрямую 
      //{
        //digitalWrite(BAND1, 0);
        //digitalWrite(BAND2, 0);
        //digitalWrite(BAND3, 0);
        //digitalWrite(BAND4, 1);
      //}
      
  if ((rx >1500000) & (rx < 3000000))   //при управлении ДПФ через 564кп2 
     {
        digitalWrite(PR1, 1);
        digitalWrite(PR2, 0);
        digitalWrite(PR3, 0);
      }
      
  if ((rx >2999999) & (rx < 4000000))   //при управлении ДПФ через 564кп2
      {
        digitalWrite(PR1, 0);
        digitalWrite(PR2, 1);
        digitalWrite(PR3, 0);
      }

  if ((rx >3999999) & (rx < 5000000))   //при управлении ДПФ через 564кп2
     {
        digitalWrite(PR1, 1);
        digitalWrite(PR2, 1);
        digitalWrite(PR3, 0);
      }

  if ((rx >4999999) & (rx < 6000000))   //при управлении ДПФ через 564кп2
     {
        digitalWrite(PR1, 0);
        digitalWrite(PR2, 0);
        digitalWrite(PR3, 1);
      }

  if ((rx >5999999) & (rx < 7000000))   //при управлении ДПФ через 564кп2
     {
        digitalWrite(PR1, 1);
        digitalWrite(PR2, 0);
        digitalWrite(PR3, 1);
      }

  if ((rx >6999999) & (rx < 8500001))   //при управлении ДПФ через 564кп2
     {
        digitalWrite(PR1, 0);
        digitalWrite(PR2, 1);
        digitalWrite(PR3, 1);
      }                 
           
    show_freq(rx);                      // частота на дисплее
  }
  
  
      
  if (digitalRead(SW_STEP) == LOW) {     // increment events
    if (button_state(SW_STEP) > 0) {
      uint32_t old_enc_inc = enc_inc;
      set_inc();
      if (enc_inc != old_enc_inc)
        settings_mark_changed();
      show_inc();           // убираем надпись шага с дисплея
      do {
      }
      while (digitalRead(SW_STEP) == LOW);
    }
  }

  rx_tx = digitalRead(PIN_RX_TX);
  if (rx_tx != rx_tx_old) {
    show_state();
    rx_tx_old = rx_tx;
  }

  val_sm = analogRead(PIN_SM);
  if ((abs(val_sm - val_sm_old)) > 3) {  // if needed draw S-meter
    show_sm();
    val_sm_old = val_sm;
  }

  eeprom_save_if_idle();
}
//---------------------------------------------------------------------------------------------------------
void rotary_encoder() {              // rotary encoder events
  uint8_t result = r.process();

  if (result) {
    ch_flag = true;
    if (result == DIR_CW)
      enc_dir = 1;
    else
      enc_dir = -1;
  }
}
//---------------------------------------------------------------------------------------------------------
void si5351_set_freq(uint32_t frequency) {
  si5351.set_freq(frequency * SI5351_FREQ_MULT, SI5351_CLK2);
}
//---------------------------------------------------------------------------------------------------------
void set_inc() {
  if (enc_inc == ENC_INC_1)
  {
    enc_inc = ENC_INC_4;             // 100кГц
    return;
  }
  if (enc_inc == ENC_INC_4)
  {
    enc_inc = ENC_INC_3;             // 10кГц
    return;
  }
  if (enc_inc == ENC_INC_3) 
  {
    enc_inc = ENC_INC_2;             // 1кГц
    return;
  }
  if (enc_inc == ENC_INC_2) 
  {
    enc_inc = ENC_INC_1;             // 100Гц
    return;
  }
  
}
//---------------------------------------------------------------------------------------------------------
void show_inc() {
  oled.setCursor(109, 3);
  oled.setFont(labels);
  if (enc_inc == ENC_INC_1)
    oled.print("7");                 // 100 Hz
  if (enc_inc == ENC_INC_2)
    oled.print("9");                 // 1 кHz
  if (enc_inc == ENC_INC_3)
    oled.print("4");                 // 10 кHz    
  else
    oled.print("0");                 // 100 кHz
}
//---------------------------------------------------------------------------------------------------------
void show_freq(uint32_t sf_rx) {
  uint16_t fr;

  oled.setFont(lcdnums14x24);
  oled.setCursor(1, 0);
  fr = sf_rx / 1000000;
  if (fr < 10)
    oled.print(':');                 // ':' is changed to ' ' in lcdnums14x24.h
  oled.print(fr);
  oled.print('.');
  fr = (sf_rx % 1000000) / 1000;
  if (fr < 100)
    oled.print('0');
  if (fr < 10)
    oled.print('0');
  oled.print(fr);
  oled.print('.');
  fr = (sf_rx % 1000) / 10;
  if (fr < 10)
    oled.print('0');
  oled.print(fr);
}
//---------------------------------------------------------------------------------------------------------
void show_state() {
  oled.setCursor(0, 3);
  oled.setFont(labels);
  if (rx_tx)
    oled.print("1");                 // "1" is "RX" in labels.h
  else
    oled.print("2");                 // "2" is "TX" in labels.h
}
//---------------------------------------------------------------------------------------------------------
void show_sm() {
  uint8_t a = 0;
  uint8_t m = 0;

  a = (val_sm + 3) / 113;            // 1024 / 9 characters for S = 1,3,5,7,8,9,+10,+20,+30
  oled.setFont(pixels);
  oled.setCursor(29, 3);
  for (m = 0; m < a; m++)
    if (m < 6)
      oled.print('7');               // '5' - hollow rectangle, 6px
    else
      oled.print('8');               // '6' - filled rectangle, 6px
  for (m = a; m < 9; m++)
    oled.print('.');                 // '.' 1px
}
//---------------------------------------------------------------------------------------------------------
uint8_t button_state(uint8_t pin_bt) {  // push button state: 0(very fast), 1(fast), 2(slow)
  uint32_t t_start;
  uint32_t t_bt = 0;
  uint8_t bt_state;

  t_start = millis();
  while (!digitalRead(pin_bt)) {
    t_bt = millis() - t_start;
    if (t_bt > 1000)
      break;
  }
  if (t_bt < 20)                     // 'nothing' for debounce
    bt_state = 0;
  else if (t_bt < 400)               // fast
    bt_state = 1;
  else                               // slow
    bt_state = 2;
  return bt_state;
}