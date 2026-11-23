(() => {
 'use strict';
 // Display allowlist only; identities are resolved from active API records, never written.
 const eligible=p=>/^(?:ndinelao|dinelao|klaudia|claudia|secilia|cecilia|hope|moses)\b/i.test(String(p.full_name||p.name||'').trim());
 const escape=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 function taskSummary(report){
  const tasks=report.sections?.tasks, rows=tasks?.status_breakdown;
  if(!Array.isArray(rows))return '<small>Task workload not measured</small>';
  const count=label=>rows.find(r=>r.label===label)?.count;
  const assigned=count('Assigned'),done=count('Completed'),overdue=count('Overdue Open');
  return '<div class="pc-directory-work">'+[['Tasks assigned',assigned],['Completed tasks',done],['Open tasks',assigned==null||done==null?null:Math.max(0,assigned-done)],['Overdue tasks',overdue]].map(([label,value])=>'<small>'+label+': <b>'+(value==null?'Not measured':Number(value))+'</b></small>').join('')+'</div><small>Portal evidence: '+(report.presence_summary?.evidence_covered_days==null?'Not measured':Number(report.presence_summary.evidence_covered_days)+' days')+' · not physical attendance</small>';
 }
 function taskTrend(report){
  const rows=report.sections?.tasks?.rows;
  if(!Array.isArray(rows))return '<p class="pc-runtime-note">Task completion trend not measured.</p>';
  const daily=new Map();
  for(const row of rows){const day=String(row.completed_at_evidence||'').slice(0,10);if(/^\d{4}-\d{2}-\d{2}$/.test(day)&&day>=report.period.from&&day<=report.period.to)daily.set(day,(daily.get(day)||0)+1);}
  const points=[...daily].sort(([a],[b])=>a.localeCompare(b));
  if(!points.length)return '<p class="pc-runtime-note">No dated task completions in the loaded evidence. This is not a zero performance score.</p>';
  const max=Math.max(...points.map(([,n])=>n)),width=600,step=width/points.length;
  return '<svg class="pc-evidence-trend" viewBox="0 0 600 110" role="img" aria-label="Completed task records by date">'+points.map(([day,n],i)=>'<g><title>'+escape(day)+': '+n+' completed task records</title><rect x="'+(i*step+2)+'" y="'+(90-80*n/max)+'" width="'+Math.max(1,step-4)+'" height="'+(80*n/max)+'" rx="3" fill="'+(i%2?'#a28ac7':'#729865')+'"/></g>').join('')+'<text x="2" y="106" font-size="11" fill="#65705f">'+escape(points[0][0])+'</text><text x="598" y="106" text-anchor="end" font-size="11" fill="#65705f">'+escape(points[points.length-1][0])+'</text></svg><small>'+points.reduce((sum,[,n])=>sum+n,0)+' dated completions · loaded task cohort; not a score or hours worked</small>';
 }
 window.HambelelaPerformancePeople={eligible,taskSummary,taskTrend};
})();
