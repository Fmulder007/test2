#include "tiny5351.h"
uint32_t SI_XTAL_FREQ = 27009000UL;
#define ch1 12835000UL
#define ch2 12855000UL
#define mypin PB3
bool  old_ch;
bool  furststart = true;


void setup() {
  pinMode(mypin, INPUT);
}

void loop() {
  uint32_t freq;
  bool new_ch = digitalRead(mypin);
  if ((new_ch != old_ch) || (furststart))
  {
    if (new_ch)
    {
      freq = ch1;
    }
    else
    {
      freq = ch2;
    }
    si5351_freq(freq, 2);
    furststart = false;
    old_ch = new_ch;
  }
}
