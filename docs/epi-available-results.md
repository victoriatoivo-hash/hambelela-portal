# Available Employee Performance results

This adds explicit partial weighted points to the existing published result contract, without changing scorecard weights, layouts, legacy evidence or locked official records.

For each KPI with eligible volume at least its configured minimum:

- available category points = observed KPI score × original internal KPI weight;
- available overall points = those category points × original category weight;
- available maximum = sum of the original weights supported by those observations.

The result is displayed as `points / supported maximum pts · Provisional`, not normalised into a full `/100` percentage. Missing weights remain missing. Below-minimum rates remain visible but do not contribute points. Official and full provisional percentages retain their existing all-required-component gates.

Zero denominator is `No eligible work` only when the source coverage is independently verified and there are no pending outcomes or withheld attribution records. Otherwise it remains missing evidence, not started, not due or needs review. No-workload adds neither zero points nor perfect points and does not redistribute weight.

Source diagnostics record the prospective capture start, observation window, runtime flags and pending capture events. Connected capture is not automatically treated as independently verified full-period coverage. October cannot become a fabricated complete month. Physical attendance and stock accuracy still require genuine evidence.

The explicit private release operation recalculates current unlocked assignments only, guards the complete legacy-evidence hash and previous/locked result heads, and never activates official scoring or financial use. GET views remain read-only. Historical stored result revisions are not edited.
