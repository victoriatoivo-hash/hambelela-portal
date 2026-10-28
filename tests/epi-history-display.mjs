import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const script=fs.readFileSync(new URL('../assets/js/employee-performance.js',import.meta.url),'utf8');
async function render(performance){
  const nodes=new Map();
  const node=key=>{if(!nodes.has(key))nodes.set(key,{innerHTML:'',textContent:'',value:'2026-10',dataset:{employeeId:'101',canReview:'1'},classList:{add(){}},remove(){},setAttribute(){},removeAttribute(){},addEventListener(){}});return nodes.get(key);};
  const root=node('root');root.querySelector=node;
  const payload={ok:true,employee:{full_name:'Synthetic <employee>',role_name:'Front Desk'},employees:[],performance};
  vm.runInNewContext(script,{document:{querySelector:()=>root},Intl,Date,URLSearchParams,location:{search:'?month=2026-10'},AbortController,
    fetch:async()=>({ok:true,json:async()=>payload})});
  await new Promise(resolve=>setImmediate(resolve));
  assert.equal(node('[data-kpi-error]').hidden,true,'render succeeds without hiding data behind an error');
  return node('[data-kpi-employee-content]').innerHTML;
}
const base={status:'not_configured',period_start:'2026-10-01',employee_name:'Synthetic A',official_score_hundredths:null,
  eligible_work_count:null,confirmed_incident_count:null,management_summary:'Not measured',previous_history:{status:'available',
    all_time_total:102,total:1,page:1,page_size:100,selected_month:'2026-09',months:{'2026-09':102},month_coverage:{'2026-09':{records:102,source_references_recorded:102,legacy_score_linked_records:1,legacy_applied_records:1}},ownership_periods_begin:'2026-10-07 08:27:10',sources:[],saved_results:[{period:'2026-09',recorded_score_hundredths:9000,methodology:'Legacy',status:'Historical result'}],
    records:[{date:'2026-10-01',reference:'<script>unsafe</script>',incident:'Evidence',category:'Orders',responsibility:'Not proven',score_effect:'No linked score event'}]}};
const html=await render(base);
assert.match(html,/Not Calculated/);assert.match(html,/Previous History/);assert.match(html,/102 historical evidence records/);
assert.match(html,/90\.0%/);assert.match(html,/Not measured eligible work/);assert.doesNotMatch(html,/<script>unsafe/);
assert.match(html,/Historical evidence coverage/);assert.match(html,/Time-bounded V2 ownership records/);
assert.match(html,/Selecting a historical month does not change the current reporting month/);
assert.match(html,/&lt;script&gt;unsafe/);assert.doesNotMatch(html,/0 distinct eligible work/);
const zero=await render({...base,status:'official',official_score_hundredths:0,eligible_work_count:10,confirmed_incident_count:10});
assert.match(zero,/0\.0%/);assert.match(zero,/Current official result/);
const missing=await render({...base,categories:{orders:{label:'Orders',weight_hundredths:10000,status:'insufficient_data',official_score_hundredths:null,measured_contribution_hundredths:null,
  metrics:{sla:{label:'Progression',numerator:0,eligible_volume:0,rate_hundredths:null,reason:'incomplete_source',status:'insufficient_data'}}}}});
assert.doesNotMatch(missing,/0 successful \/ 0/);assert.match(missing,/incomplete source/);
const provisional=await render({...base,status:'provisional',default_weights_provisional:true,measurement_begins_at:'2026-10-27 18:42:04'});
assert.match(provisional,/Provisional V2 measurement begins: 2026-10-27 18:42:04/);
assert.match(provisional,/Official V2 scoring is not activated/);
assert.doesNotMatch(provisional,/V2 measurement is not yet configured/);
const observed=await render({...base,status:'provisional',result_id:44,categories:{orders:{label:'Orders',weight_hundredths:2500,status:'insufficient_data',
  official_score_hundredths:null,metrics:{completion:{label:'Completion',numerator:15,eligible_volume:20,rate_hundredths:7500,
    reason:'incomplete_source',status:'provisional',measurement_explanation:'Observed work is provisional: required source coverage is not verified for the whole month.'}}}}});
assert.match(observed,/KPIs with observed work/);assert.match(observed,/15 successful \/ 20/);
assert.match(observed,/required source coverage is not verified for the whole month/);
assert.doesNotMatch(observed,/Overall performance<\/span><strong>0/);
const unhealthy=await render({...base,data_may_be_stale:true,processing_health:{status:'stale',last_success_at:'2026-10-28 08:00:00'},
  function_scope:{description:'Verified Front Desk coverage only — not overall Marketing performance'}});
assert.match(unhealthy,/Data may be stale/);assert.match(unhealthy,/Last successful background run: 2026-10-28 08:00:00/);
assert.match(unhealthy,/not overall Marketing performance/);
console.log('PASS: unavailable counts, preserved historical result, genuine calculated zero, missing source, safe escaping and profile render');
