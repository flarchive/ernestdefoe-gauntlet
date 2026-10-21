/*
 * Gauntlet's styles live in its lazy chunk, not in forum.css: every page of
 * the forum loads forum.css, and only tournament pages need these. Colours
 * come from Flarum's own custom properties, so themes and dark mode apply.
 */
const css = `
.Gauntlet{--g-accent:var(--primary-color);--g-line:var(--control-bg);--g-ok:#2f9e44;--g-bad:#e03131}
.Gauntlet-head{margin-bottom:16px}
.Gauntlet-head h2{margin:0 0 4px;font-size:22px}
.Gauntlet-meta{color:var(--muted-color);font-size:13px;display:flex;flex-wrap:wrap;gap:4px 12px;margin:0;padding:0;list-style:none}
.Gauntlet-meta .icon{margin-right:4px}
.Gauntlet-description{white-space:pre-line;margin:12px 0}
.Gauntlet-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}
.Gauntlet-tabs{display:flex;gap:4px;border-bottom:1px solid var(--g-line);margin:16px 0;overflow-x:auto;scrollbar-width:none}
.Gauntlet-tabs .Button{border-radius:0;border-bottom:2px solid transparent;flex-shrink:0}
.Gauntlet-tabs .Button.active{border-bottom-color:var(--g-accent);color:var(--text-color);font-weight:600}
.Gauntlet-notice{background:var(--control-bg);border-radius:var(--border-radius);padding:10px 14px;margin:12px 0}
.Gauntlet-notice.is-warn{background:var(--control-warning-bg);color:var(--control-warning-color)}
.Gauntlet-bracketSection{margin-bottom:24px}
.Gauntlet-sectionTitle{font-size:15px;margin:0 0 6px}
.Gauntlet-roundChips{display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;margin-bottom:4px}
.Gauntlet-chip{flex-shrink:0;font-size:12px;padding:4px 10px;border-radius:999px;background:var(--control-bg)}
.Gauntlet-bracketScroll{overflow-x:auto;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;padding-bottom:8px}
.Gauntlet-svg{display:block;font-size:13px;font-family:inherit}
.Gauntlet-colTitle{fill:var(--muted-color);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.Gauntlet-line{fill:none;stroke:var(--g-line);stroke-width:2}
.Gauntlet-line.is-done{stroke:var(--muted-color-light,var(--muted-color))}
.Gauntlet-boxBg{fill:var(--body-bg);stroke:var(--g-line);stroke-width:1.5}
.Gauntlet-boxDivider{stroke:var(--g-line)}
.Gauntlet-rowBg{fill:transparent}
.Gauntlet-match.is-open .Gauntlet-boxBg,.Gauntlet-match.is-reported .Gauntlet-boxBg,.Gauntlet-match.is-awaiting .Gauntlet-boxBg{stroke:var(--g-accent)}
.Gauntlet-match.is-disputed .Gauntlet-boxBg,.Gauntlet-match.is-live .Gauntlet-boxBg{stroke:var(--g-bad)}
.Gauntlet-match.is-bye{opacity:.55}
.Gauntlet-match.is-clickable,.Gauntlet-row.is-pickable{cursor:pointer}
.Gauntlet-match.is-clickable:hover .Gauntlet-boxBg,.Gauntlet-match:focus-visible .Gauntlet-boxBg{stroke:var(--g-accent);stroke-width:2.5}
.Gauntlet-match:focus,.Gauntlet-row:focus{outline:none}
.Gauntlet-row.is-pickable:hover .Gauntlet-rowBg,.Gauntlet-row:focus-visible .Gauntlet-rowBg{fill:var(--control-bg)}
.Gauntlet-row.is-picked .Gauntlet-rowBg{fill:color-mix(in srgb,var(--g-accent) 18%,transparent)}
.Gauntlet-row.is-right .Gauntlet-rowBg{fill:color-mix(in srgb,var(--g-ok) 18%,transparent)}
.Gauntlet-row.is-wrong .Gauntlet-name{text-decoration:line-through;fill:var(--muted-color)}
.Gauntlet-name{fill:var(--text-color)}
.Gauntlet-row.is-won .Gauntlet-name,.Gauntlet-row.is-won .Gauntlet-score{font-weight:700}
.Gauntlet-row.is-lost .Gauntlet-name,.Gauntlet-row.is-lost .Gauntlet-score{fill:var(--muted-color)}
.Gauntlet-name.is-tbd{fill:var(--muted-color);font-style:italic}
.Gauntlet-row.is-guess .Gauntlet-name{font-style:italic;fill:var(--muted-color)}
.Gauntlet-row.is-picked.is-guess .Gauntlet-name{fill:var(--text-color)}
.Gauntlet-seed{fill:var(--muted-color);font-size:11px}
.Gauntlet-score{fill:var(--text-color);font-variant-numeric:tabular-nums}
.Gauntlet-verdict.is-right{fill:var(--g-ok);font-weight:700}
.Gauntlet-verdict.is-wrong{fill:var(--g-bad);font-weight:700}
.Gauntlet-initial{fill:#fff;font-size:11px;font-weight:700}
.Gauntlet-live,.Gauntlet-flag{fill:var(--g-bad);font-size:10px;font-weight:700;text-transform:uppercase}
.Gauntlet-table{width:100%;border-collapse:collapse;font-size:14px}
.Gauntlet-table th{text-align:left;color:var(--muted-color);font-weight:600;font-size:12px;padding:6px 8px;border-bottom:1px solid var(--g-line)}
.Gauntlet-table td{padding:8px;border-bottom:1px solid var(--g-line);vertical-align:middle}
.Gauntlet-table .num{text-align:right;font-variant-numeric:tabular-nums}
.Gauntlet-tableWrap{overflow-x:auto}
.Gauntlet-person{display:inline-flex;align-items:center;gap:8px;min-width:0}
.Gauntlet-person .Avatar{--size:24px;flex-shrink:0}
.Gauntlet-person img.Gauntlet-teamLogo{width:24px;height:24px;object-fit:contain;flex-shrink:0}
.Gauntlet-roster{color:var(--muted-color);font-size:12px;margin-top:2px}
.Gauntlet-pill{display:inline-block;font-size:11px;font-weight:600;padding:1px 8px;border-radius:999px;background:var(--control-bg);color:var(--control-color);white-space:nowrap}
.Gauntlet-pill.is-registration,.Gauntlet-pill.is-open{background:var(--control-success-bg);color:var(--control-success-color)}
.Gauntlet-pill.is-running,.Gauntlet-pill.is-reported,.Gauntlet-pill.is-awaiting{background:color-mix(in srgb,var(--g-accent) 18%,transparent);color:var(--text-color)}
.Gauntlet-pill.is-disputed,.Gauntlet-pill.is-cancelled{background:var(--control-danger-bg);color:var(--control-danger-color)}
.Gauntlet-list{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.Gauntlet-card{display:block;border:1px solid var(--g-line);border-radius:var(--border-radius);padding:12px 14px;color:inherit}
.Gauntlet-card:hover{text-decoration:none;border-color:var(--g-accent)}
.Gauntlet-card h3{margin:0 0 4px;font-size:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.Gauntlet-matches{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}
.Gauntlet-matchCard{border:1px solid var(--g-line);border-radius:var(--border-radius);padding:8px 10px;cursor:pointer;background:var(--body-bg);text-align:left;width:100%;color:inherit;font:inherit}
.Gauntlet-matchCard:hover,.Gauntlet-matchCard:focus-visible{border-color:var(--g-accent)}
.Gauntlet-matchCard .side{display:flex;justify-content:space-between;gap:8px;padding:2px 0}
.Gauntlet-matchCard .side.is-won{font-weight:700}
.Gauntlet-matchCard .side.is-lost{color:var(--muted-color)}
.Gauntlet-matchCard header{display:flex;justify-content:space-between;color:var(--muted-color);font-size:12px;margin-bottom:4px}
.Gauntlet-roundTitle{font-size:14px;margin:16px 0 8px;color:var(--muted-color)}
.Gauntlet-scoreForm{display:flex;align-items:center;gap:8px;margin:12px 0}
.Gauntlet-scoreForm .FormControl{width:80px;text-align:center}
.Gauntlet-scoreForm label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.Gauntlet-form fieldset{border:0;padding:0;margin:0 0 20px}
.Gauntlet-form legend{font-weight:700;margin-bottom:8px;font-size:15px}
.Gauntlet-form .Form-group{margin-bottom:12px}
.Gauntlet-grid2{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
.Gauntlet-search{position:relative}
.Gauntlet-searchResults{list-style:none;margin:4px 0 0;padding:0;border:1px solid var(--g-line);border-radius:var(--border-radius);max-height:240px;overflow:auto}
.Gauntlet-searchResults button{display:flex;gap:8px;align-items:center;width:100%;padding:6px 10px;border:0;background:none;color:inherit;text-align:left;cursor:pointer}
.Gauntlet-searchResults button:hover{background:var(--control-bg)}
.Gauntlet-searchResults img{width:20px;height:20px;object-fit:contain}
.Gauntlet-board .is-me td{background:color-mix(in srgb,var(--g-accent) 10%,transparent)}
.Gauntlet-stats{display:grid;gap:10px;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-bottom:16px}
.Gauntlet-stat{border:1px solid var(--g-line);border-radius:var(--border-radius);padding:10px 12px}
.Gauntlet-stat strong{display:block;font-size:20px}
.Gauntlet-stat span{color:var(--muted-color);font-size:12px}
@media (min-width:768px){.Gauntlet-roundChips{display:none}}
@media (max-width:767px){.Gauntlet-head h2{font-size:19px}.Gauntlet-table .hide-phone{display:none}}
`;

let injected = false;

export default function injectStyles(): void {
  if (injected || typeof document === 'undefined') return;
  injected = true;

  const style = document.createElement('style');
  style.id = 'gauntlet-styles';
  style.textContent = css;
  document.head.appendChild(style);
}
