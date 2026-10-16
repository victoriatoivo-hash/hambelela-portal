import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync('assets/js/orders-board.js','utf8');
const code=source.slice(source.indexOf('  function restoreOrdersTablePosition('),source.indexOf('  function captureOrdersBoardPositions('));
const frames=[],vertical=[];
let busy=false;
const surface={scrollLeft:0,scrollTop:0};
const context={mobileOrdersViewport:{matches:true},mobileOrdersScrolling:()=>busy,body:{querySelectorAll:()=>[{dataset:{groupCard:'today'},querySelector:()=>surface}]},getOrdersScrollContainer:()=>surface,window:{scrollX:0,scrollY:500,scrollTo:p=>vertical.push(p),requestAnimationFrame:fn=>frames.push(fn)}};
vm.createContext(context);vm.runInContext(code,context);
const saved={groupKey:'today',table:null,tableLeft:470,tableTop:90,windowX:0,windowY:100};
context.restoreOrdersTablePosition(saved);
assert.equal(surface.scrollLeft,470);assert.equal(surface.scrollTop,0);assert.equal(vertical.length,0);assert.equal(frames.length,0);
// A late return from an editor must not undo scrolling on the surviving surface.
surface.scrollLeft=620;context.restoreOrdersTablePosition({...saved,table:{isConnected:true}});assert.equal(surface.scrollLeft,620);
busy=true;context.restoreOrdersTablePosition(saved);assert.equal(surface.scrollLeft,620);
busy=false;context.mobileOrdersViewport.matches=false;context.restoreOrdersTablePosition(saved);
assert.equal(surface.scrollLeft,470);assert.equal(surface.scrollTop,90);assert.equal(vertical.length,1);assert.equal(frames.length,1);
console.log('Mobile restore: horizontal only, no delayed frame, no stale gesture restoration; desktop restore preserved.');
