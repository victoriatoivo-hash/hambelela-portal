# Responsive QA Matrix

Use viewport dimensions rather than device or laptop names. Test at 100% browser zoom first, then repeat critical workflows at 80%, 90%, 110%, and 125%.

## Viewports

- [ ] 1920 x 1080
- [ ] 1600 x 900
- [ ] 1536 x 864
- [ ] 1440 x 900
- [ ] 1366 x 768
- [ ] 1280 x 800
- [ ] 1280 x 720
- [ ] 1180 x 820
- [ ] 1024 x 768
- [ ] 820 x 1180
- [ ] 768 x 1024
- [ ] 430 x 932
- [ ] 412 x 915
- [ ] 390 x 844
- [ ] 375 x 812
- [ ] 360 x 800
- [ ] 320 x 568

## Checks Per Page

- [ ] Sidebar does not overlap content.
- [ ] Mobile sidebar opens as a drawer, traps focus, closes on Escape, and restores focus.
- [ ] Page title and actions do not overlap.
- [ ] Filters remain readable and usable.
- [ ] Stat widgets reflow without clipped text.
- [ ] Dense tables scroll inside their own wrappers.
- [ ] No unexpected page-level horizontal overflow appears.
- [ ] Dropdowns and date pickers remain inside the viewport.
- [ ] Modal headers and footers remain reachable.
- [ ] Side panels fit the viewport.
- [ ] Save actions do not jump the page or reset scroll.
- [ ] Keyboard focus remains visible.
- [ ] Portrait and landscape rotation preserve usable state.

## Portal Areas

- [ ] Main app dashboard
- [ ] Packing List
- [ ] Packing Tools
- [ ] Upload Invoice
- [ ] New Packing Item
- [ ] Order Board
- [ ] Task Management
- [ ] Error Log
- [ ] Courier / Waybill Queue
- [ ] Bookkeeping
- [ ] Inventory
- [ ] Employee Accounts and HR
- [ ] Notifications
- [ ] Login
- [ ] Details side panels
- [ ] Custom dropdowns
- [ ] Date and time pickers
- [ ] Confirmation dialogs

## Phase Sign-off

Record failures with the page, viewport, zoom level, observed overflow or overlap, and a screenshot. Do not mark a phase complete while a regression in an existing workflow remains open.
