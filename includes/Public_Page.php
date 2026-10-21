<?php
namespace WPFlatCore;

defined( 'ABSPATH' ) || exit;

/** The standalone, theme-free page frame every public WPFlat document (invoice, payslip, contract, signing) is drawn in. */
class Public_Page {

	public static function shell( $title, $body, $biz ) {
		$font = Assets::font_base();
		ob_start();
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo esc_html( $title ); ?></title>
<style>
@font-face{font-family:'Inter';font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $font . 'inter-latin.woff2' ); ?>') format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}
@font-face{font-family:'Inter';font-weight:100 900;font-display:swap;src:url('<?php echo esc_url( $font . 'inter-latin-ext.woff2' ); ?>') format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+20A0-20AB,U+20AD-20C0,U+2113}
:root{--ink:#0e121b;--ink2:#525866;--muted:#868e9d;--line:rgba(14,18,27,.07);--bg:#f3f5f8;--card:#fff;--deep:#222530;--soft:#f6f8fa}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;letter-spacing:-.006em;-webkit-font-smoothing:antialiased;font-feature-settings:'cv11','ss03'}
.bd-bar{position:sticky;top:0;z-index:5;background:rgba(255,255,255,.82);backdrop-filter:saturate(180%) blur(14px);box-shadow:0 1px 0 var(--line)}
.bd-bar__in{max-width:920px;margin:0 auto;padding:11px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px}
.bd-bar__who{display:flex;align-items:center;gap:10px;color:var(--ink2);font-weight:500;min-width:0}.bd-bar__who b{color:var(--ink);font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bd-bar__who img{height:24px;width:auto;border-radius:5px}
.bd-bar__act{display:flex;align-items:center;gap:8px}.bd-bar__act em{font-style:normal;color:#8a5300;background:#fffaeb;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
.bd-bar button,.bd-bar a.solid{border:0;font:inherit;font-weight:600;padding:8px 15px;border-radius:9px;cursor:pointer;text-decoration:none;line-height:1.4;display:inline-block}
.bd-bar .ghost{background:#fff;color:var(--ink);box-shadow:0 0 0 1px rgba(14,18,27,.12),0 1px 2px rgba(14,18,27,.05)}
.bd-bar .solid{background:var(--deep);color:#fff;box-shadow:0 1px 2px rgba(14,18,27,.3)}
.bd-wrap{max-width:920px;margin:0 auto;padding:26px 16px 56px}
.bd-doc{position:relative;background:var(--card);border-radius:18px;padding:50px 54px 40px;box-shadow:0 0 0 1px rgba(14,18,27,.04),0 18px 48px -22px rgba(14,18,27,.22)}
.bd-doc--empty{text-align:center;padding:72px 32px}.bd-doc--empty h1{font-size:22px;margin:0 0 8px}
.bd-head{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:30px}
.bd-brand{display:flex;gap:16px;align-items:flex-start}.bd-logo{display:block;height:58px;width:auto;max-width:200px;object-fit:contain;border-radius:6px}
.bd-biz{font-size:17px;font-weight:700;letter-spacing:-.02em}.bd-muted{color:var(--muted)}.bd-sm{font-size:12px}.bd-mt{margin-top:10px}.bd-strong{font-weight:600}.bd-lg{font-size:16px}.bd-late{color:#b42318}
.bd-title{text-align:right}.bd-eyebrow{font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted)}.bd-num{font-size:26px;font-weight:700;letter-spacing:-.035em;line-height:1.15;margin:3px 0 10px}
.bd-pill{display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:650;background:#f2f5f8;color:#222530}
.bd-pill--ok{background:#e0faec;color:#0b4627}.bd-pill--warn{background:#fffaeb;color:#624c18}.bd-pill--bad{background:#ffebec;color:#681219}.bd-pill--info{background:#ebf1ff;color:#122368}
.bd-for{margin:-6px 0 22px}.bd-for h1{margin:4px 0 0;font-size:24px;line-height:1.25;font-weight:700;letter-spacing:-.03em;color:var(--ink)}
.bd-sum{display:grid;grid-template-columns:1.35fr .8fr 1.15fr;gap:14px;margin-bottom:30px}
.bd-sum>div{border-radius:14px;padding:18px 20px}
.bd-sum__who,.bd-sum__dates{background:var(--soft)}
.bd-sum__dates{display:flex;flex-direction:column;gap:14px;justify-content:center}
.bd-sum__due{background:var(--deep);color:#fff;display:flex;flex-direction:column;justify-content:center;text-align:left}
.bd-sum__due .bd-k{color:rgba(255,255,255,.6)}.bd-sub{color:rgba(255,255,255,.7);font-size:12.5px;margin-top:4px}
.bd-k{font-size:11px;font-weight:650;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.bd-big{font-size:30px;font-weight:700;letter-spacing:-.04em;line-height:1.1}
table{width:100%;border-collapse:collapse}th{font-size:11px;font-weight:650;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);text-align:left;padding:0 0 10px;box-shadow:inset 0 -1px 0 var(--line)}
td{padding:15px 0;box-shadow:inset 0 -1px 0 var(--line);vertical-align:top}tbody tr:last-child td{box-shadow:none}.r{text-align:right}th.r{text-align:right}
.bd-lines td:first-child{padding-right:16px}.bd-lines--fixed td:first-child,.bd-lines--fixed th:first-child{width:auto}.bd-grp+.bd-grp{margin-top:22px}
.bd-grp--card{border:1px solid #dfe3ea;border-radius:14px}
.bd-grp__h{margin:0;display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:12px 18px;background:#eceff4;border-radius:13px 13px 0 0;color:var(--ink);font-size:12.5px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.bd-grp__h em{font-style:normal;font-weight:500;letter-spacing:0;text-transform:none;font-size:12.5px;color:var(--ink2)}
.bd-grp--card .bd-lines th{padding:12px 18px 8px;box-shadow:none}.bd-grp--card .bd-lines td{padding:14px 18px}.bd-grp--card .bd-lines td{box-shadow:inset 0 -1px 0 var(--line)}.bd-grp--card .bd-lines tbody tr:last-child td{box-shadow:none}.bd-grp--card .bd-lines td:first-child{padding-right:16px}
.bd-block.bd-grp--card{margin-top:22px}.bd-grp--card .bd-sched th{padding:12px 0 8px 18px;box-shadow:none;white-space:nowrap}.bd-grp--card .bd-sched td{padding:14px 0 14px 18px;box-shadow:inset 0 -1px 0 var(--line)}.bd-grp--card .bd-sched th:last-child,.bd-grp--card .bd-sched td:last-child{padding-right:18px}.bd-grp--card .bd-sched tbody tr:last-child td{box-shadow:none}.bd-grp--card .bd-sched td.r,.bd-grp--card .bd-sched th.r{padding-left:10px}.bd-grp--card .bd-scroll{overflow-x:auto}
.bd-grp__sum{display:flex;justify-content:space-between;padding:13px 18px;margin-top:0;background:var(--soft);border-radius:0 0 13px 13px;box-shadow:inset 0 1px 0 #dfe3ea;color:var(--ink);font-weight:700;font-size:14px}
.bd-grp:not(.bd-grp--card) .bd-grp__sum{display:none}.bd-show-s{display:none}
.bd-totals{display:flex;justify-content:flex-end;margin-top:14px}.bd-totals__box{width:340px}
.row{display:flex;justify-content:space-between;padding:6px 0;color:var(--ink2)}.row.total{font-size:16px;font-weight:700;color:var(--ink);margin-top:6px;padding-top:12px;box-shadow:inset 0 1px 0 var(--line)}
.row.due{font-size:17px;font-weight:700;color:var(--ink);background:var(--soft);margin:8px -14px 0;padding:12px 14px;border-radius:10px}
.bd-block{margin-top:34px}h2{font-size:11.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 10px}
.bd-scroll{overflow-x:auto}.bd-sched td,.bd-sched th{padding-right:14px}.bd-sched td.r,.bd-sched th.r{white-space:nowrap}.bd-sched td:last-child,.bd-sched th:last-child{padding-right:0}
.bd-pay{margin-top:38px;background:var(--soft);border-radius:16px;padding:26px 26px 22px;scroll-margin-top:70px}
.bd-pay__h h2{margin:0 0 4px;color:var(--ink);font-size:16px;letter-spacing:-.02em;text-transform:none;font-weight:700}.bd-pay__h p{margin:0 0 18px;color:var(--ink2)}
.bd-methods{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:14px;align-items:start}
.bd-m{display:block;background:#fff;border-radius:13px;padding:18px 18px 16px;text-decoration:none;color:inherit;box-shadow:0 0 0 1px rgba(14,18,27,.05),0 1px 2px rgba(14,18,27,.04)}
.bd-m__h{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}.bd-m__t{font-weight:650;font-size:14.5px;letter-spacing:-.015em;display:block}
.bd-m__s{color:var(--ink2);font-size:13px;margin-top:8px}.bd-m__s--body{margin-top:0}
.bd-chip{font-size:11px;font-weight:700;letter-spacing:.05em;color:var(--ink2);background:var(--soft);padding:2px 8px;border-radius:999px}
.bd-kv{margin:0}.bd-kv>div{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:8px 0;box-shadow:inset 0 -1px 0 var(--line)}.bd-kv>div:last-child{box-shadow:none}
.bd-kv dt{color:var(--muted);font-size:12.5px;flex:none}.bd-kv dd{margin:0;font-weight:600;display:flex;align-items:center;gap:8px;text-align:right;word-break:break-word}
.bd-copy{border:0;background:var(--soft);color:var(--ink2);font:inherit;font-size:11.5px;font-weight:650;padding:3px 9px;border-radius:7px;cursor:pointer}.bd-copy:hover{background:#e9edf2}.bd-copy.is-done{background:#e0faec;color:#0b4627}
.bd-m--link{background:var(--deep);color:#fff;transition:transform .12s,box-shadow .12s;display:flex;flex-direction:column;box-shadow:0 6px 18px -8px rgba(34,37,48,.55)}.bd-m--link:hover{transform:translateY(-1px);box-shadow:0 10px 24px -8px rgba(34,37,48,.6)}
.bd-m--link .bd-m__s{color:rgba(255,255,255,.68);margin-top:4px}.bd-m__go{margin-top:16px;display:inline-flex;align-items:center;gap:6px;font-weight:650;background:#fff;color:var(--deep);padding:9px 14px;border-radius:9px;align-self:flex-start}.bd-m__go i{font-style:normal}
.bd-pay__note{margin-top:16px;color:var(--ink2);font-size:13px}
.bd-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:28px;margin-top:34px}.bd-cols>div>div{color:var(--ink2)}
.bd-foot{margin-top:34px;padding-top:18px;box-shadow:inset 0 1px 0 var(--line);text-align:center;color:var(--muted);font-size:13px}
.bd-stamp{position:absolute;right:56px;top:150px;transform:rotate(-12deg);border:3px solid #1fc16b;color:#1fc16b;font-weight:800;font-size:34px;letter-spacing:.1em;padding:2px 18px;border-radius:10px;opacity:.28;pointer-events:none}
@media screen and (max-width:760px){.bd-wrap{padding:0 0 32px}.bd-doc{border-radius:0;padding:26px 18px}.bd-head{flex-direction:column}.bd-title{text-align:left}.bd-sum{grid-template-columns:1fr}.bd-totals__box{width:100%}.bd-stamp{right:20px;top:120px}.bd-hide-s{display:none}.bd-show-s{display:block}.bd-pay{padding:20px 14px 16px}.bd-methods{grid-template-columns:1fr}}
.bd-paper{display:block;width:100%}.bd-paper>thead,.bd-paper>tfoot{display:none}.bd-paper>tbody,.bd-paper>tbody>tr,.bd-paper>tbody>tr>td{display:block;padding:0;box-shadow:none}
@page{margin:0}
@media print{
html,body{background:#fff;width:100%}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
.no-print{display:none!important}
.bd-wrap{padding:0;max-width:none;margin:0}
.bd-paper{display:table;width:100%;border-collapse:collapse}
.bd-paper>thead{display:table-header-group}.bd-paper>tfoot{display:table-footer-group}.bd-paper>tbody{display:table-row-group}.bd-paper>tbody>tr{display:table-row}
table.bd-paper>thead>tr>td,table.bd-paper>tbody>tr>td,table.bd-paper>tfoot>tr>td{display:table-cell;padding:0;box-shadow:none}
.bd-pg-sp{height:13mm}
.bd-doc{box-shadow:none;border-radius:0;padding:0 13mm;max-width:none;overflow:visible;zoom:.9}
.bd-grp--card{-webkit-box-decoration-break:clone;box-decoration-break:clone}.bd-grp__h{break-after:avoid;page-break-after:avoid}.bd-grp__sum{break-before:avoid}.bd-head,.bd-sum,.bd-totals,.bd-pay,.bd-m,.bd-block,.bd-cols>div,.bd-foot,.bd-lines tr,.bd-sched tr{break-inside:avoid;page-break-inside:avoid}
.bd-lines thead,.bd-sched thead{display:table-header-group}
.bd-lines tbody tr:last-child{break-after:avoid}
h2,.bd-pay__h{break-after:avoid;page-break-after:avoid}
.bd-sum{margin-bottom:22px}.bd-pay{margin-top:28px}.bd-m{box-shadow:none;border:1px solid #e3e7ec}.bd-m--link{box-shadow:none;border-color:var(--deep)}
.bd-sum>div,.bd-pay{box-shadow:none}
.bd-scroll{overflow:visible}
}
</style>
</head>
<body>
<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
