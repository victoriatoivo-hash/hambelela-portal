import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync('assets/js/portal.js','utf8');
const start=source.indexOf('  const taskReminderSound =');
const end=source.indexOf('  const portalNotificationPoller =',start);
let played=0,volume=0;
class FakeAudio {constructor(url){assert.match(url,/assets\/audio\/task-assigned\.mp3$/);}play(){played++;volume=this.volume;return Promise.resolve();}}
const context={Audio:FakeAudio,document:{querySelector:()=>null},window:{},console};
vm.createContext(context);vm.runInContext(source.slice(start,end)+'\nglobalThis.sound=taskReminderSound;',context);
context.sound.configure({sound_enabled:0,sound_prompt_seen:1});context.sound.play('assigned');assert.equal(played,0);
context.sound.configure({sound_enabled:1,sound_prompt_seen:1,sound_volume:25});context.sound.play('assigned');assert.equal(played,1);assert.equal(volume,.25);
const condition=source.match(/if \((claimed && notification.sound_key && \(!isMarketing[^\n]+)\) taskReminderSound.play\(notification.sound_key\);/)[1];
function eligible(created,claimed=true){return vm.runInNewContext(condition,{claimed,notification:{sound_key:'assigned',created_at:created},isMarketing:true,marketingPageStarted:Date.parse('2026-10-12T12:00:00+02:00')});}
assert.equal(eligible('2026-10-12 11:59:00'),false);assert.equal(eligible('2026-10-12 12:01:00'),true);assert.equal(eligible('2026-10-12 12:01:00',false),false);
console.log('PASS sound preference/volume, silent refresh, new-arrival sound and claim gate');
