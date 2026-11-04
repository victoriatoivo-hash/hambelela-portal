from pathlib import Path
from openpyxl import load_workbook
root=Path(__file__).resolve().parents[1]/'verification/vat-export'
wb=load_workbook(root/'2026-10.xlsx');ws=wb.active
assert ws.title=='Input VAT 2026-10'
assert ws['A6'].value.strftime('%Y-%m-%d')=='2026-10-01'
assert ws['A127'].value.strftime('%Y-%m-%d')=='2026-10-31'
assert ws['B6'].value=='=1+1' and ws['B6'].data_type=='s'
assert ws['C6'].value=='00123'
assert ws['D6'].value=='Supplier & <items>'
assert ws['I6'].value==0.15
assert ws['E128'].value=='=SUM(E6:E127)'
assert ws.freeze_panes=='A6' and ws.auto_filter.ref=='A5:L127'
values=load_workbook(root/'2026-10.xlsx',data_only=True).active
assert [values[f'{col}128'].value for col in 'EFG']==[14030,1830,12200]
empty=load_workbook(root/'2026-08.xlsx',data_only=True).active
assert empty['E6'].value==0
print('Excel reader: dates, saved amounts, totals, safe literal text, filters and empty month PASS')
