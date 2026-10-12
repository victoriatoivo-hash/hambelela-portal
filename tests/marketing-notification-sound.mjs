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
assert.match(source,/if \(claimed && notification.sound_key\) taskReminderSound.play/);
assert.match(source,/if\(!isMarketing\)window.setTimeout\(close, 5000\)/);
assert.match(source,/if \(!claimed && !isMarketing\)/);
assert.match(source,/data-marketing-ack/);
assert.match(source,/toast.showModal\(\)/);
console.log('PASS sound preference/volume, single delivery claim and persistent centred acknowledgement');
