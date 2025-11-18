window.HS = window.HS || {};
HS.openPicker = async function(){
// Simple: lists CSV/XLSX in a configured subfolder under import root
const sub = prompt('Enter subfolder under import root (e.g., Inline/2025-10):','');
if (sub===null) return null;
const res = await HS.api('picker.list', { sub });
const files = (res && res.files) || [];
if (!files.length) { alert('No files found'); return null; }
const names = files.map((f,i)=>`${i+1}. ${f.name} (${f.size} B)` ).join('\n');
const pick = prompt(`Pick file (1-${files.length}):\n\n${names}`,'1');
const idx = (parseInt(pick,10)||1)-1; if (!files[idx]) return null;
return files[idx].path;
};