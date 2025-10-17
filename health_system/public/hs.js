(function(){
const base = window.HS_BASE || '/health_system';
window.HS_ROUTES = {
api: base + '/controllers/hs_logic.php'
};
window.HS = window.HS || {};
HS.initJobsTable();
})();