'use strict';
const EEPROM_SIZE=1024,SLOT_SIZE=510,SLOT_OFFSETS=[0,510],BAND_COUNT=31,BAND_START=39,BAND_SIZE=15;
const $=id=>document.getElementById(id);let eeprom=null,activeSlot=1,slotDirty=false;
const generalFields=[
['stp','Шаг настройки','uint8',''],['old_band','Старый номер диапазона','uint8','Служебное поле'],['number_of_bands','Количество диапазонов','uint8',''],['cmode','Режим управления','bool',''],
['bfo_freq','Частота BFO','uint32','Гц'],['lsb_lo_freq','Гетеродин LSB','uint32','Гц'],['usb_lo_freq','Гетеродин USB','uint32','Гц'],['Si_Xtall_Freq','Частота опорного генератора Si5351','uint32','Гц'],
['batt_cal','Калибровка батареи','uint8',''],['reverse_encoder','Обратное направление энкодера','bool','Нет / да'],['mem_enc_div','Делитель энкодера памяти','uint8',''],['temp_cal','Калибровка температуры','int8','°C'],
['b4','Калибровка B4','uint8',''],['b3','Калибровка B3','uint8',''],['b2','Калибровка B2','uint8',''],['b1','Калибровка B1','uint8',''],
['cwdelay','Задержка CW','uint8',''],['cwtone','Тон CW','uint8',''],['cwtype','Тип CW','uint8',''],['PowerDoubler','Удвоитель мощности','bool',''],
['if_shift','Сдвиг ПЧ','int32','Гц'],['ALC_RX','ALC при приёме','bool',''],['ATT_RX','Аттенюатор при приёме','bool','']
];
function crc8(a){let c=0;for(const b of a){c^=b;for(let i=0;i<8;i++)c=(c&128)?((c<<1)^7)&255:(c<<1)&255;}return c;}
function crc16(a){let c=65535;for(const b of a){c^=b<<8;for(let i=0;i<8;i++)c=(c&32768)?((c<<1)^4129)&65535:(c<<1)&65535;}return c;}
function u32(a,o){return(a[o]|(a[o+1]<<8)|(a[o+2]<<16)|(a[o+3]<<24))>>>0;}
function put32(a,o,v){v=Number(v)>>>0;a[o]=v&255;a[o+1]=(v>>>8)&255;a[o+2]=(v>>>16)&255;a[o+3]=(v>>>24)&255;}
function s32(a,o){return u32(a,o)|0;}
function setStatus(t,c=''){$('status').textContent=t;$('status').className='status '+c;}
function parseHex(text){
 const data=new Uint8Array(EEPROM_SIZE);data.fill(255);const seen=new Uint8Array(EEPROM_SIZE);let upper=0,eof=false,countData=0;
 const lines=text.replace(/^\uFEFF/,'').split(/\r?\n/);
 for(let li=0;li<lines.length;li++){const line=lines[li].trim();if(!line)continue;if(eof)throw Error(`Строка ${li+1}: данные после EOF`);if(line[0]!==':'||line.length<11||(line.length-1)%2)throw Error(`Строка ${li+1}: неверный формат Intel HEX`);
  const b=[];for(let i=1;i<line.length;i+=2){const pair=line.slice(i,i+2);if(!/^[0-9a-fA-F]{2}$/.test(pair))throw Error(`Строка ${li+1}: неверная HEX-цифра`);b.push(parseInt(pair,16));}
  const n=b[0],addr=(b[1]<<8)|b[2],type=b[3];if(b.length!==n+5)throw Error(`Строка ${li+1}: неверная длина записи`);if(b.reduce((s,x)=>s+x,0)&255)throw Error(`Строка ${li+1}: ошибка контрольной суммы`);const payload=b.slice(4,4+n);
  if(type===0){const start=upper+addr;if(start+n>EEPROM_SIZE)throw Error(`Строка ${li+1}: адрес за пределами EEPROM 1024 байта`);for(let j=0;j<n;j++){const p=start+j;if(seen[p]&&data[p]!==payload[j])throw Error(`Строка ${li+1}: конфликт данных по адресу ${p}`);data[p]=payload[j];seen[p]=1;}countData++;}
  else if(type===1){if(n!==0)throw Error('Неверная запись EOF');eof=true;}
  else if(type===2){if(n!==2)throw Error('Неверная запись типа 02');upper=((payload[0]<<8)|payload[1])<<4;}
  else if(type===4){if(n!==2)throw Error('Неверная запись типа 04');upper=((payload[0]<<8)|payload[1])<<16;}
  else if(type!==3&&type!==5)throw Error(`Неподдерживаемый тип Intel HEX: ${type}`);
 }
 if(!eof)throw Error('В файле отсутствует запись EOF.');if(!countData)throw Error('В файле нет данных EEPROM.');return{data,written:seen.reduce((s,x)=>s+x,0)};
}
function record(addr,type,bytes){const a=[bytes.length,(addr>>8)&255,addr&255,type,...bytes];a.push((-a.reduce((s,x)=>s+x,0))&255);return':'+a.map(x=>x.toString(16).padStart(2,'0')).join('').toUpperCase();}
function makeHex(){const lines=[];for(let p=0;p<EEPROM_SIZE;p+=16)lines.push(record(p,0,Array.from(eeprom.slice(p,p+16))));lines.push(record(0,1,[]));return lines.join('\r\n')+'\r\n';}
function base(s){return(s-1)*SLOT_SIZE;}
function slotBytes(s){return eeprom.slice(base(s),base(s)+SLOT_SIZE);}
function slotCheck(s){const b=slotBytes(s),isEmpty=b.every(x=>x===255),stored=b[508]|b[509]<<8;let valid=0,empty=0,bad=0;for(let i=0;i<BAND_COUNT;i++){const r=b.slice(BAND_START+i*BAND_SIZE,BAND_START+(i+1)*BAND_SIZE);if(r.every(x=>x===255))empty++;else if((r[0]&240)===160&&crc8(r.slice(0,14))===r[14])valid++;else bad++;}return{blank:isEmpty,sig:b[504]===75,ver:b[505]===1,id:b[506]===s,commit:b[507]===165,crc:stored===crc16(b.slice(0,507)),stored,valid,empty,bad,calc:crc16(b.slice(0,507))};}
function field(root,name,label,type,value,unit=''){
 const w=document.createElement('div');w.className='field';const l=document.createElement('label');l.textContent=label;l.htmlFor='f_'+name;let x;
 if(type==='bool'){x=document.createElement('select');x.innerHTML='<option value="0">Выключено</option><option value="1">Включено</option>';x.value=Number(value)?'1':'0';}
 else{x=document.createElement('input');x.type='number';x.step='1';x.value=value;x.inputMode='numeric';}
 x.id='f_'+name;x.dataset.name=name;x.dataset.type=type;x.addEventListener('change',()=>{if(type==='bool')return;let v=Number(x.value);if(!Number.isFinite(v))v=0;const lo=type==='int8'?-128:type==='int32'?-2147483648:0,hi=type==='int8'?127:type==='uint8'?255:type==='int32'?2147483647:4294967295;x.value=String(Math.max(lo,Math.min(hi,Math.trunc(v))));});
 w.append(l,x);if(unit){const sm=document.createElement('small');sm.textContent=unit;w.append(sm);}root.append(w);return x;
}
function generalData(s){const b=slotBytes(s),o={};if(b.every(x=>x===255)){const v=[3,0,30,1,34441500,13557900,13561000,27008100,208,0,6,-2,200,130,80,40,50,70,0,1,0,0,0];generalFields.forEach((f,i)=>o[f[0]]=v[i]);return o;}let p=0;for(const f of generalFields){const[n,,t]=f;o[n]=t==='uint32'?u32(b,p):t==='int32'?s32(b,p):t==='int8'?(b[p]<<24)>>24:b[p];p+=(t==='uint32'||t==='int32')?4:1;}return o;}
function renderGeneral(){const b=slotBytes(activeSlot),d=generalData(activeSlot);$('generalFields').innerHTML='';for(const[n,l,t,u]of generalFields)field($('generalFields'),n,l,t,d[n],u);const box=$('currentBandField');box.innerHTML='';const sel=document.createElement('select');sel.id='currentBand';sel.style.width='100%';for(let i=0;i<31;i++){const o=document.createElement('option');o.value=i;o.textContent=`Диапазон ${i+1}`;sel.append(o);}sel.value=String(b.every(x=>x===255)?0:Math.min(30,b[38]));box.append(sel);}
function renderBands(){
 const b=slotBytes(activeSlot),root=$('bands');root.innerHTML='';
 for(let i=0;i<31;i++){const off=BAND_START+i*15,r=b.slice(off,off+15),empty=r.every(x=>x===255),valid=!empty&&(r[0]&240)===160&&crc8(r.slice(0,14))===r[14],d=document.createElement('details');d.className='band'+(empty?' empty':'')+(!empty&&!valid?' invalid':'');
  const sm=document.createElement('summary'),a=document.createElement('span'),tag=document.createElement('span');a.textContent=`Диапазон ${i+1}`;tag.className='tag';tag.textContent=empty?'Пустой':valid?`VFO ${u32(r,2).toLocaleString('ru-RU')} Гц`:'Запись требует проверки';sm.append(a,tag);d.append(sm);
  const fs=document.createElement('div');fs.className='grid fields';const cw=document.createElement('label');cw.className='check';const chk=document.createElement('input');chk.type='checkbox';chk.checked=!empty;chk.addEventListener('change',()=>{fs.classList.toggle('hidden',!chk.checked);d.classList.toggle('empty',!chk.checked);});cw.append(chk,document.createTextNode('Диапазон заполнен'));fs.append(cw);
  const mw=document.createElement('div');mw.className='field';const ml=document.createElement('label');ml.textContent='Режим';const mode=document.createElement('select');mode.innerHTML='<option value="0">LSB</option><option value="1">USB</option>';mode.value=empty?'0':String(r[1]&1);mw.append(ml,mode);fs.append(mw);
  const seq=field(fs,'seq'+i,'Номер записи (0–15)','uint8',empty?0:r[0]&15,'Служебный счётчик');seq.max='15';
  const freq=field(fs,'freq'+i,'Частота VFO','uint32',empty?0:u32(r,2),'Гц');
  const min=field(fs,'min'+i,'Нижняя граница','uint32',empty?0:u32(r,6)/10,'МГц · шаг 0,1');min.step='0.1';
  const max=field(fs,'max'+i,'Верхняя граница','uint32',empty?0:u32(r,10)/10,'МГц · шаг 0,1');max.step='0.1';
  if(empty)fs.classList.add('hidden');d.append(fs);d.dataset.band=i;d.controls={chk,mode,seq,freq,min,max};root.append(d);
 }
}
function renderIntegrity(){const lines=[];for(let s=1;s<=2;s++){const c=slotCheck(s);lines.push(c.blank?`Слот ${s}: пустой, не инициализирован.`:`Слот ${s}: CRC16 ${c.crc?'OK':'ошибка'} (в файле 0x${c.stored.toString(16).padStart(4,'0').toUpperCase()}, расчёт 0x${c.calc.toString(16).padStart(4,'0').toUpperCase()}); диапазонов OK: ${c.valid}, пустых: ${c.empty}, с ошибками: ${c.bad}.`);}$('integrity').textContent=lines.join(' ');}
function render(){if(!eeprom)return;$('editor').classList.remove('hidden');document.querySelectorAll('.slot-tab').forEach(x=>x.classList.toggle('active',Number(x.dataset.slot)===activeSlot));const c=slotCheck(activeSlot);$('slotStatus').textContent=c.blank?`Слот ${activeSlot} пустой. Его можно заполнить вручную или инициализировать.`:`Слот ${activeSlot}: ${c.sig?'сигнатура OK':'неверная сигнатура'} · ${c.ver?'версия OK':'неизвестная версия'} · ${c.id?'номер OK':'номер не совпадает'} · ${c.commit?'маркер OK':'нет маркера завершения'} · CRC16 ${c.crc?'верна':'не совпадает'} · записей диапазонов: ${c.valid}, пустых: ${c.empty}, с ошибками: ${c.bad}. При экспорте служебные поля и CRC будут восстановлены.`;$('slotStatus').className='status '+(c.blank||c.sig&&c.ver&&c.id&&c.commit&&c.crc&&!c.bad?'ok':'error');renderGeneral();renderBands();renderIntegrity();slotDirty=false;}
function writeUI(){const b=eeprom,o=base(activeSlot);let p=o;for(const[n,,t]of generalFields){const x=document.querySelector('#generalFields [data-name="'+n+'"]'),v=Number(x.value)||0;if(t==='uint32'||t==='int32')put32(b,p,v);else b[p]=v&255;p+=(t==='uint32'||t==='int32')?4:1;}b[o+38]=Number($('currentBand').value)||0;
 document.querySelectorAll('.band').forEach(d=>{const i=Number(d.dataset.band),c=d.controls,off=o+BAND_START+i*15;if(!c.chk.checked){b.fill(255,off,off+15);return;}const r=new Uint8Array(15);r[0]=160|(Math.max(0,Math.min(15,Number(c.seq.value)||0)));r[1]=Number(c.mode.value)&1;put32(r,2,Number(c.freq.value)||0);put32(r,6,Math.round((Number(c.min.value)||0)*10));put32(r,10,Math.round((Number(c.max.value)||0)*10));r[14]=crc8(r.slice(0,14));b.set(r,off);});
}
function finalizeSlot(s){const o=base(s);if(eeprom.slice(o,o+510).every(x=>x===255))return;for(let i=0;i<31;i++){const p=o+BAND_START+i*15,r=eeprom.slice(p,p+15);if(r.every(x=>x===255))continue;r[0]=160|(r[0]&15);r[14]=crc8(r.slice(0,14));eeprom.set(r,p);}eeprom[o+504]=75;eeprom[o+505]=1;eeprom[o+506]=s;eeprom[o+507]=165;const c=crc16(eeprom.slice(o,o+507));eeprom[o+508]=c&255;eeprom[o+509]=c>>>8;}
function download(){if(!eeprom)return;if(slotDirty)writeUI();finalizeSlot(1);finalizeSlot(2);const blob=new Blob([makeHex()],{type:'text/plain;charset=ascii'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='eeprom.hex';document.body.append(a);a.click();a.remove();URL.revokeObjectURL(url);setStatus('Файл eeprom.hex сформирован. CRC записей диапазонов и обоих слотов пересчитаны.','ok');render();}
function initializeSlot(s){const o=base(s);eeprom.fill(255,o,o+510);const vals=[3,0,30,1,34441500,13557900,13561000,27008100,208,0,6,254,200,130,80,40,50,70,0,1,0,0,0];let p=o;for(let i=0;i<generalFields.length;i++){const t=generalFields[i][2],v=vals[i]??0;if(t==='uint32'||t==='int32')put32(eeprom,p,v);else eeprom[p]=v&255;p+=(t==='uint32'||t==='int32')?4:1;}eeprom[o+38]=0;finalizeSlot(s);}
$('hexFile').addEventListener('change',async ev=>{const f=ev.target.files[0];if(!f)return;try{const p=parseHex(await f.text());eeprom=p.data;activeSlot=1;$('fileInfo').textContent=`${f.name} · считано ${p.written} из 1024 байт`;$('export').disabled=false;setStatus(`Файл загружен: ${p.written} байт данных.`,'ok');render();}catch(e){eeprom=null;$('editor').classList.add('hidden');$('export').disabled=true;setStatus(e.message,'error');}ev.target.value='';});
$('editor').addEventListener('input',()=>slotDirty=true);$('editor').addEventListener('change',()=>slotDirty=true);document.querySelectorAll('.slot-tab').forEach(x=>x.addEventListener('click',()=>{if(!eeprom)return;if(slotDirty)writeUI();activeSlot=Number(x.dataset.slot);render();}));
$('newFile').addEventListener('click',()=>{eeprom=new Uint8Array(1024);eeprom.fill(255);activeSlot=1;initializeSlot(1);initializeSlot(2);$('fileInfo').textContent='Новая EEPROM · 1024 байта';$('export').disabled=false;setStatus('Создан новый образ EEPROM. Заполните настройки и сохраните HEX.','ok');render();});
$('initSlot').addEventListener('click',()=>{if(!eeprom||!confirm(`Полностью инициализировать слот ${activeSlot}? Его содержимое будет заменено.`))return;if(slotDirty)writeUI();initializeSlot(activeSlot);render();setStatus(`Слот ${activeSlot} инициализирован.`,'ok');});
$('copySlot').addEventListener('click',()=>{if(!eeprom)return;if(slotDirty)writeUI();const target=activeSlot===1?2:1;if(!confirm(`Скопировать все данные слота ${activeSlot} в слот ${target}?`))return;const src=base(activeSlot),dst=base(target);eeprom.set(eeprom.slice(src,src+510),dst);eeprom[dst+506]=target;finalizeSlot(target);activeSlot=target;render();setStatus(`Данные скопированы в слот ${target}.`,'ok');});
$('export').addEventListener('click',download);$('exportBottom').addEventListener('click',download);
