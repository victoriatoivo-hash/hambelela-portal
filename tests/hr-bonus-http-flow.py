"""Exercise the actual HR HTTP handlers against an isolated MariaDB database.
No production credentials, email or employee records are used."""
import os, pathlib, re, shutil, subprocess, tempfile, time, urllib.request, urllib.parse, urllib.error
from html.parser import HTMLParser
root = pathlib.Path(__file__).resolve().parents[1]
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): return None
opener=urllib.request.build_opener(NoRedirect())
def check(condition, message):
    if not condition: raise AssertionError(message)
class Form(HTMLParser):
    def __init__(self):
        super().__init__(); self.active=False; self.fields={}
    def handle_starttag(self, tag, attrs):
        d=dict(attrs)
        if tag=='form' and d.get('id')=='hrGeneratePayrollForm': self.active=True
        if self.active and tag=='input' and d.get('name'): self.fields[d['name']]=d.get('value','')
    def handle_endtag(self,tag):
        if tag=='form': self.active=False
with tempfile.TemporaryDirectory() as tmp:
    tmp=pathlib.Path(tmp)
    hr=tmp/'apps/hr-portal'
    shutil.copytree(root/'apps/hr-portal',hr)
    # Keep real DB/auth/rendering logic, but prevent any outgoing email.
    (hr/'includes/email.php').write_text('<?php function emailPayslipReady(...$x){} function emailPayrollRunGenerated(...$x){}')
    (tmp/'fixture-auth.php').write_text("""<?php
session_name('hambelela_hr_test_session');
session_start();
$who=(int)($_SERVER['HTTP_X_FIXTURE_USER']??1);
$_SESSION['user_id']=$who;
$_SESSION['user']=['id'=>$who,'name'=>'Fixture User','role'=>$who===1?'admin':'employee','emp_id'=>$who===1?0:$who-1];
""")
    dbsetup=tmp/'fixture-db.php'
    dbsetup.write_text("""<?php
$db=new PDO('mysql:host=127.0.0.1;port=33329','root','fixture-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE IF NOT EXISTS bonus_flow_fixture');
$db->exec('USE bonus_flow_fixture');
$db->exec(file_get_contents($argv[1]));
$db->exec("INSERT INTO employees(id,emp_number,first_name,last_name,basic_salary,start_date,status) VALUES (1,'F001','Award','Employee',5000,'2024-01-01','active'),(2,'F002','NoBonus','Employee',4000,'2026-09-01','active')");
$db->exec("INSERT INTO users(id,name,email,password,role,employee_id) VALUES (1,'Fixture Admin','admin@example.invalid','not-a-login','admin',NULL),(2,'Award Employee','award@example.invalid','not-a-login','employee',1),(3,'NoBonus Employee','none@example.invalid','not-a-login','employee',2)");
$db->exec("INSERT INTO payroll_runs(period_label,period_month,period_year,status,generated_by) VALUES ('October 2026',10,2026,'finalised',1)");
""")
    subprocess.run(['php',str(dbsetup),str(hr/'install.sql')],check=True)
    env=dict(os.environ,HAMBELELA_HR_DB_HOST='127.0.0.1;port=33329',HAMBELELA_HR_DB_NAME='bonus_flow_fixture',HAMBELELA_HR_DB_USER='root',HAMBELELA_HR_DB_PASS='fixture-only')
    base='http://127.0.0.1:18829/apps/hr-portal/'
    log=tmp/'server.log'
    with log.open('w') as lf:
        server=subprocess.Popen(['php','-d','auto_prepend_file='+str(tmp/'fixture-auth.php'),'-S','127.0.0.1:18829','-t',str(tmp)],env=env,stdout=lf,stderr=lf)
        try:
            for _ in range(50):
                try:
                    urllib.request.urlopen(base+'payroll.php',timeout=1).close(); break
                except Exception: time.sleep(.15)
            def request(path,who=1,data=None,cookie=''):
                headers={'X-Fixture-User':str(who)}
                if cookie: headers['Cookie']=cookie
                req=urllib.request.Request(base+path,data=None if data is None else urllib.parse.urlencode(data).encode(),headers=headers)
                try: res=opener.open(req,timeout=20)
                except urllib.error.HTTPError as e: res=e
                with res: return res.code,res.headers,res.read().decode('utf-8','replace')
            status,headers,body=request('payroll.php')
            check(status==200 and 'October 2026' in body,'initial existing payroll page')
            form=Form();form.feed(body)
            token=form.fields.get('bonus_csrf','')
            cookie=next(x.split(';',1)[0] for x in headers.get_all('Set-Cookie',[]) if x.startswith('__Host-HRPayrollCSRF='))
            check(len(token)==64 and 'bonus[1]' in form.fields and 'bonus[2]' in form.fields,'actual rendered form controls')
            data={'action':'generate','period_month':'11','period_year':'2026','bonus_csrf':token,'bonus[1]':'2500.00','bonus_paye[1]':'','bonus[2]':'','bonus_paye[2]':''}
            status,_,body=request('payroll.php',data=data,cookie=cookie)
            check(status==422 and 'enter the verified additional PAYE' in body and 'value="2500.00"' in body and 'id="generateModal"' in body,'missing PAYE: visible error and preserved draft')
            data['bonus_paye[1]']='100'
            status,_,body=request('payroll.php',data={**data,'bonus_csrf':'invalid'},cookie=cookie)
            check(status==422 and 'form expired' in body,'invalid CSRF rejected visibly')
            status,headers,body=request('payroll.php',data=data,cookie=cookie)
            check(status==303 and 'msg=generated' in headers.get('Location',''),'valid bonus POST commits and redirects: '+str(status))
            location=headers['Location']
            status,_,body=request(location,cookie=cookie)
            check(status==200 and 'November 2026' in body and '2,500.00' in body and 'Payroll generated successfully' in body,'admin summary and bonus')
            link=re.search(r'payroll\.php\?run=\d+&payslip=(\d+)',body)
            check(link is not None,'payslip link present')
            # Obtain exact fixture payslip ids and verify all stored arithmetic.
            sql="""$d=new PDO('mysql:host=127.0.0.1;port=33329;dbname=bonus_flow_fixture','root','fixture-only');echo json_encode($d->query('SELECT p.* FROM payslips p JOIN payroll_runs r ON r.id=p.run_id WHERE r.period_month=11 ORDER BY employee_id')->fetchAll(PDO::FETCH_ASSOC));"""
            import json
            rows=json.loads(subprocess.check_output(['php','-r',sql]))
            check(len(rows)==2 and float(rows[0]['bonus_amount'])==2500 and float(rows[0]['net_salary'])==7355 and float(rows[1]['bonus_amount'])==0 and float(rows[1]['net_salary'])==3964,'persisted exact payroll totals')
            award_id=rows[0]['id']; non_id=rows[1]['id']
            status,_,body=request(location.split('&')[0]+'&payslip='+str(award_id),cookie=cookie)
            check(status==200 and 'Performance Bonus</td>' in body and '7,500.00' in body and '7,355.00' in body,'admin payslip')
            status,_,body=request('my-payslips.php?id='+str(award_id),who=2)
            check(status==200 and 'Performance Bonus</td>' in body and '7,355.00' in body,'employee payslip')
            status,_,body=request('my-payslips.php',who=2)
            check(status==200 and 'My Performance Bonuses' in body,'employee bonus history')
            status,_,body=request('self-service.php',who=2)
            check(status==200 and 'My Performance Bonus' in body and '2,500.00' in body,'employee dashboard bonus')
            status,_,body=request('my-payslips.php?id='+str(award_id),who=3)
            check(status==200 and 'Performance Bonus</td>' not in body and 'My Performance Bonuses' not in body,'peer cannot access bonus')
            status,_,body=request('my-payslips.php?id='+str(non_id),who=3)
            check(status==200 and 'Performance Bonus</td>' not in body and '3,964.00' in body,'non-qualifier normal payslip')
            status,_,body=request('self-service.php',who=3)
            check(status==200 and 'My Performance Bonus' not in body,'non-qualifier dashboard')
            status,_,body=request('payroll.php',data=data,cookie=cookie)
            check(status==422 and 'already exists' in body,'duplicate period rejected')
            status,headers,body=request('payroll.php',data={**data,'period_month':'12','bonus[1]':'','bonus_paye[1]':''},cookie=cookie)
            check(status==303 and 'msg=generated' in headers.get('Location',''),'normal payroll after bonus')
            status,_,body=request(headers['Location'],cookie=cookie)
            check(status==200 and 'December 2026' in body and '<th>Bonus</th>' not in body,'no automatic bonus carryover')
            print('Full HTTP payroll flow passed: validation, CSRF, database commit, admin and employee payslips, dashboard privacy, duplicate guard and no carryover')
        except Exception:
            print(log.read_text()[-6000:])
            raise
        finally:
            server.terminate();server.wait(timeout=10)
