"""Read-only comparison. Never uploads live source or logs its contents."""
import ftplib, hashlib, io, os, subprocess, json, re
paths=['apps/operations/operations.php','apps/operations/orders-board-action.php','apps/operations/sync-orders.php','assets/js/orders-board.js']
ftp=ftplib.FTP(os.environ['FTP_SERVER'],timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
for path in paths:
    buf=io.BytesIO();ftp.retrbinary('RETR '+path,buf.write);data=buf.getvalue()
    live=data.decode('utf-8-sig').replace('\r\n','\n').strip()
    repo=subprocess.check_output(['git','show','b5a00de6:'+path]).decode().replace('\r\n','\n').strip()
    results={}
    for name in ['ops_website_payment_methods','ops_payment_method_map','ops_normalize_payment_code','ops_normalize_website_payment_method','ops_wc_payment_method','ops_wc_payment_allocations','ops_wc_payment_source','ops_apply_initial_portal_paid_confirmation','ops_sync_order_payment_allocations','ops_board_order_notes','ops_board_sync_website_orders','ops_wc_notes']:
        pattern=r'(?ms)^function '+name+r'\(.*?^}'
        a=re.search(pattern,live);b=re.search(pattern,repo)
        if b: results[name]={'exists':bool(a),'matches':bool(a and a.group()==b.group())}
    print(json.dumps({'path':path,'sha256':hashlib.sha256(data).hexdigest(),'bytes':len(data),'matches_trimmed':live==repo,'functions':results}))
ftp.quit()
