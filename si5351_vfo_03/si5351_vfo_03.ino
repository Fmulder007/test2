// 8.04.2019, Arduino IDE v1.8.9, LZ2WSG, KN34PC
// Si5351 VFO CLK0, OLED 0.91" 128x32 display, S-meter          for arbalet-1k
//---------------------------------------------------------------------------------------------------------
#include "src/Rotary.h"              // http://www.buxtronix.net/2011/10/rotary-encoders-done-properly.html
#include "src/si5351.h"              // https://github.com/etherkit/Si5351Arduino, v2.1.0
#include "src/SSD1306AsciiAvrI2c.h"  // https://github.com/greiman/SSD1306Ascii

#define DEFAULT_RX    4000000        // частота при включении
#define BAND_L        1500000        // начало диапазона
#define BAND_H        8500000        // конец диапазона
#define IF_FREQ       62496500       // частота ПЧ
#define IF2_LSB_FREQ  2496500        // вторая ПЧ, LSB — 2496,5 кГц
#define IF2_USB_FREQ  2500000        // вторая ПЧ, USB — 2500 кГц
#define LSB_USB_PIN   A3             // выбор LSB/USB
#define SW_STEP       A0             // выбор шага
#define PIN_SM        A1             // вход S-метра
#define PIN_RX_TX     A2             // RX/TX педаль

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
bool IF2_PIN_PREV;
//---------------------------------------------------------------------------------------------------------
void setup() {
  pinMode(SW_STEP, INPUT_PULLUP);        // вход с инверсией (включается землей)
  pinMode(PIN_RX_TX, INPUT_PULLUP);      // вход с инверсией (включается землей)
  pinMode(LSB_USB_PIN, INPUT_PULLUP);     // выбор LSB/USB с внутренней подтяжкой
  
  //pinMode(BAND1, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  //pinMode(BAND2, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  //pinMode(BAND3, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  //pinMode(BAND4, OUTPUT);                // при управлении ДПФ напрямую, "1"-5В, "0"-земля, если закомментировать, выход будет с внутренним резистором
  
  pinMode(PR1, OUTPUT);                  // при управлении ДПФ через 564кп2,"1"-5В, "0"-земля
  pinMode(PR2, OUTPUT);                  // при управлении ДПФ через 564кп2,"1"-5В, "0"-земля
  pinMode(PR3, OUTPUT);                  // при управлении ДПФ через 564кп2,"1"-5В, "0"-земля
  
  analogReference(INTERNAL);          // ADC reference voltage 5,0V or 1,1V (S-meter max IN voltage) чувствительность S-метра

  attachInterrupt(0, rotary_encoder, CHANGE);
  attachInterrupt(1, rotary_encoder, CHANGE);

  si5351.init(SI5351_CRYSTAL_LOAD_8PF, 0, 26430);           // crystal 25.000 MHz, последние цифры - коррекция частоты кварца
  IF2_PIN_PREV = !digitalRead(LSB_USB_PIN);               // гарантируем первую настройку CLK0
  IF2_SET();                                             // установка второй ПЧ на выходе CLK0
  si5351.set_freq(60000000*SI5351_FREQ_MULT,SI5351_CLK1);  // частота на выходе 1
 
  //si5351.drive_strength(SI5351_CLK0,SI5351_DRIVE_4MA);    // регулировка выхода CLK0, регулируется током 2,4,6,8 МА ???
  si5351.drive_strength(SI5351_CLK1,SI5351_DRIVE_4MA);    // регулировка выхода CLK1, регулируется током 2,4,6,8 МА ???
  si5351.drive_strength(SI5351_CLK2,SI5351_DRIVE_4MA);    // регулировка выхода CLK2, регулируется током 2,4,6,8 МА ???
  
  oled.begin(&Adafruit128x32, OLED_I2C_ADDR);

  rx = DEFAULT_RX;
  
  rx_tx = digitalRead(PIN_RX_TX);
  rx_tx_old = ! rx_tx;
  show_state();

  enc_inc = ENC_INC_4;     // шаг при включении ???
  show_inc();            // убираем надпись шага с дисплея
}
//---------------------------------------------------------------------------------------------------------
void IF2_SET() {
  bool IF2_PIN_STATUS = digitalRead(LSB_USB_PIN);

  if (IF2_PIN_STATUS != IF2_PIN_PREV) {
    if (IF2_PIN_STATUS)
      si5351.set_freq(IF2_LSB_FREQ * SI5351_FREQ_MULT, SI5351_CLK0);
    else
      si5351.set_freq(IF2_USB_FREQ * SI5351_FREQ_MULT, SI5351_CLK0);
  }

  IF2_PIN_PREV = IF2_PIN_STATUS;
}
//---------------------------------------------------------------------------------------------------------
void loop() {
  if (ch_flag) {
    ch_flag = false;
    rx += enc_dir * enc_inc;
    enc_dir = 0;
    rx = constrain(rx, BAND_L, BAND_H);
    //si5351_set_freq(rx - IF_FREQ);      // неверно !!!???                           // 
    si5351_set_freq(rx + IF_FREQ);      // гетеродин "вверх"                          // частота гетеродина на выходе si5351
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
      set_inc();
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
  oled.setCursor(2, 3);
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
  oled.setCursor(24, 3);
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
