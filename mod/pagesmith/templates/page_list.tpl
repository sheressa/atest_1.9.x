{TOTAL_ROWS}
<table cellpadding="5">
  <tr>
    <th width="5%">{ID_SORT} {ID_LABEL}</th>
    <th>{TITLE_SORT} {TITLE_LABEL}</th>
    <th width="25%">{CREATE_DATE_SORT} {CREATED_LABEL}<br />
        {LAST_UPDATED_SORT} {UPDATED_LABEL}</th>
    <th width="20%">{ACTION_LABEL}</th>
  </tr>
<!-- BEGIN listrows -->
  <tr>
    <td>{ID}</td>
    <td>{TITLE}</td>
    <td>{CREATE_DATE}<br />{LAST_UPDATED}</td>
    <td class="smaller">{ACTION}</td>
  </tr>
<!-- END listrows -->
</table>
{EMPTY_MESSAGE}
<div align="center">
  <b>{PAGE_LABEL}</b><br />
  {PAGES}<br />
  {LIMITS}
</div>
{SEARCH}
