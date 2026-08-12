(function () {
  const enabled = /^(localhost|127\.0\.0\.1)$/i.test(window.location.hostname) || window.FAM_DEV_TABLE_AUDIT === true;
  function check(table, label = '') {
    if (!table) return;
    table.querySelectorAll('thead th:not([scope])').forEach(th => th.setAttribute('scope', 'col'));
    if (!enabled || !window.console) return;
    const headers = table.querySelectorAll('thead th').length;
    const cols = table.querySelectorAll('colgroup col').length;
    const firstRow = table.querySelector('tbody tr');
    const cells = firstRow ? [...firstRow.children].reduce((sum, cell) => sum + Number(cell.getAttribute('colspan') || 1), 0) : 0;
    if (cols && headers && cols !== headers) {
      console.warn(`[FAM table audit] ${label || table.className}: colgroup count ${cols} does not match header count ${headers}.`);
    }
    if (cells && headers && cells !== headers) {
      console.warn(`[FAM table audit] ${label || table.className}: first row cell count ${cells} does not match header count ${headers}.`);
    }
  }
  function scan() {
    document.querySelectorAll('table').forEach(table => check(table, table.id || table.className));
  }
  window.FAMTableAudit = { check, scan };
  document.addEventListener('DOMContentLoaded', scan);
})();
