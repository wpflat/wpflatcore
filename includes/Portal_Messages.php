<?php
namespace BizyCore;

use BizyCore\Services\Messages;

defined( 'ABSPATH' ) || exit;

/** The portal's Messages page: a small self-contained chat app (HTML + CSS + vanilla JS) talking to the messages REST routes. */
class Portal_Messages {

	/** @param array $ctx portal context (client, ids, preview, user) */
	public static function render( array $ctx, $thread_id ) {
		$orgs = array();
		foreach ( $ctx['ids'] as $oid ) {
			$o = Portal::client( $oid );
			if ( $o ) {
				$orgs[] = array( 'id' => (int) $o['id'], 'name' => $o['name'] );
			}
		}
		$cfg = array(
			'rest'      => Urls::api(),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'thread'    => (int) $thread_id,
			'readonly'  => (bool) $ctx['preview'],
			'client'    => (int) $ctx['client']['id'],
			'orgs'      => $orgs,
			'maxBytes'  => Messages::max_bytes(),
			'accept'    => '.' . implode( ',.', Messages::ALLOWED ),
			'allowed'   => Messages::ALLOWED,
			'base'      => Portal::url( 'messages' ),
			'previewAs' => $ctx['preview'] ? (int) $ctx['client']['id'] : 0,
			'me'        => $ctx['preview'] ? '' : $ctx['user']->display_name,
		);
		ob_start();
		?>
<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
<div id="bdm" class="bdm" data-state="list">
	<aside class="bdm__list" aria-label="Conversations">
		<div class="bdm__lh"><h1>Messages</h1><?php if ( empty( $cfg['readonly'] ) ) : ?><button type="button" class="btn bdm__new" id="bdm-new">New message</button><?php endif; ?></div>
		<input type="search" id="bdm-q" class="bdm__search" placeholder="Search conversations" aria-label="Search conversations">
		<div class="bdm__tabs" role="tablist"><button type="button" class="is-on" data-st="open">Open</button><button type="button" data-st="closed">Closed</button></div>
		<div id="bdm-threads" class="bdm__threads" aria-live="polite"></div>
	</aside>
	<section class="bdm__conv" aria-label="Conversation">
		<div class="bdm__empty" id="bdm-empty"><div class="bdm__bubble-art">💬</div><strong>Select a conversation</strong><p>Or start a new one, share files and get answers here.</p></div>
		<div class="bdm__chat" id="bdm-chat" hidden>
			<header class="bdm__ch">
				<button type="button" class="bdm__back" id="bdm-back" aria-label="Back to conversations">←</button>
				<div class="bdm__ct"><strong id="bdm-subject"></strong><span id="bdm-sub" class="muted"></span></div>
				<span id="bdm-status" class="badge badge--neutral" hidden>Closed</span>
			</header>
			<div class="bdm__stream" id="bdm-stream" tabindex="0"></div>
			<div class="bdm__typing" id="bdm-typing" hidden><span></span><span></span><span></span><em id="bdm-typing-t"></em></div>
			<?php if ( empty( $cfg['readonly'] ) ) : ?>
			<form class="bdm__composer" id="bdm-form" autocomplete="off">
				<div class="bdm__chips" id="bdm-chips"></div>
				<div class="bdm__row">
					<button type="button" class="bdm__icon" id="bdm-attach" aria-label="Attach files" title="Attach files"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.5l-8.6 8.6a5.5 5.5 0 0 1-7.8-7.8l9-9a3.7 3.7 0 0 1 5.2 5.2l-9 9a1.8 1.8 0 0 1-2.6-2.6l8.3-8.3"/></svg></button>
					<textarea id="bdm-text" rows="1" maxlength="5000" placeholder="Write a message…  (Enter to send, Shift+Enter for a new line)" aria-label="Message"></textarea>
					<button type="submit" class="btn bdm__send" id="bdm-send">Send</button>
				</div>
				<input type="file" id="bdm-file" multiple hidden accept="<?php echo esc_attr( $cfg['accept'] ); ?>">
				<div class="bdm__drop" id="bdm-drop">Drop files to attach</div>
			</form>
			<?php else : ?>
			<div class="bdm__ro">Preview: staff see the conversation but cannot reply as the client.</div>
			<?php endif; ?>
		</div>
	</section>
</div>
<dialog id="bdm-dlg" class="bdm__dlg">
	<form method="dialog" id="bdm-nform">
		<h2>New message</h2>
		<label>About<select id="bdm-nproj"><option value="0">General question</option></select></label>
		<?php if ( count( $orgs ) > 1 ) : ?><label>Organisation<select id="bdm-norg"><?php foreach ( $orgs as $o ) : ?><option value="<?php echo (int) $o['id']; ?>"><?php echo esc_html( $o['name'] ); ?></option><?php endforeach; ?></select></label><?php endif; ?>
		<label>Subject<input type="text" id="bdm-nsub" maxlength="190" placeholder="What is this about?"></label>
		<label>Message<textarea id="bdm-nbody" rows="5" maxlength="5000" placeholder="Write your message…"></textarea></label>
		<div class="bdm__chips" id="bdm-nchips"></div>
		<div class="bdm__err" id="bdm-nerr" role="alert" hidden></div>
		<div class="bdm__dact"><button type="button" class="btn btn--ghost" id="bdm-nattach">Attach files</button><span style="flex:1"></span><button type="button" class="btn btn--ghost" id="bdm-ncancel">Cancel</button><button type="submit" class="btn" id="bdm-ncreate">Send</button></div>
		<input type="file" id="bdm-nfile" multiple hidden accept="<?php echo esc_attr( $cfg['accept'] ); ?>">
	</form>
</dialog>
<div id="bdm-lb" class="bdm__lb" hidden><button type="button" aria-label="Close">×</button><img alt=""></div>
<script id="bdm-cfg" type="application/json"><?php echo wp_json_encode( $cfg ); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
<script><?php echo self::js(); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
		<?php
		return ob_get_clean();
	}

	private static function css() {
		return <<<'CSS'
main.wrap:has(.bdm){max-width:1240px}
.bdm{display:grid;grid-template-columns:340px 1fr;height:calc(100vh - 140px);min-height:520px;background:var(--card);border:1px solid var(--ring);border-radius:14px;overflow:hidden}
.bdm__list{display:flex;flex-direction:column;border-right:1px solid var(--ring);min-height:0}
.bdm__lh{display:flex;align-items:center;justify-content:space-between;padding:16px 16px 8px}.bdm__lh h1{font-size:20px;margin:0}.bdm__new{padding:7px 14px;font-size:13.5px}
.bdm__search{margin:4px 16px 8px;height:38px;border:1px solid var(--ring2);border-radius:9px;padding:0 12px;background:var(--bg);color:var(--ink);font:inherit;font-size:14px}
.bdm__tabs{display:flex;gap:4px;padding:0 16px 8px}.bdm__tabs button{border:0;background:transparent;color:var(--muted);padding:5px 10px;border-radius:7px;font:inherit;font-size:13px;cursor:pointer}.bdm__tabs button.is-on{background:var(--neu-bg);color:var(--ink)}
.bdm__threads{overflow-y:auto;flex:1;padding:0 8px 8px}
.bdm__t{display:flex;gap:11px;padding:11px 10px;border-radius:10px;cursor:pointer;border:0;background:transparent;width:100%;text-align:left;font:inherit;color:inherit}.bdm__t:hover{background:var(--bg)}.bdm__t.is-on{background:var(--neu-bg)}
.bdm__av{flex:none;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;font-weight:500;font-size:14px;color:#fff;background:#7a5af8}
.bdm__tb{min-width:0;flex:1;display:block}.badge[hidden]{display:none}.bdm__tt{display:flex;justify-content:space-between;gap:8px;min-width:0}.bdm__tt strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500}.bdm__tt time{color:var(--muted);font-size:12px;white-space:nowrap}
.bdm__tp{display:block;color:var(--muted);font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}.bdm__t.is-unread .bdm__tp,.bdm__t.is-unread strong{color:var(--ink);font-weight:600}
.bdm__tag{display:inline-block;margin-top:3px;padding:1px 8px;border-radius:999px;background:var(--neu-bg);color:var(--neu);font-size:11.5px}
.bdm__dot{flex:none;align-self:center;min-width:20px;height:20px;border-radius:999px;background:var(--accent);color:var(--on);font-size:11.5px;display:grid;place-items:center;padding:0 6px;font-weight:600}
.bdm__conv{display:flex;flex-direction:column;min-width:0;min-height:0;position:relative}
.bdm__empty{margin:auto;text-align:center;color:var(--muted);padding:30px}.bdm__empty strong{display:block;color:var(--ink);font-size:17px;margin:6px 0 2px}.bdm__bubble-art{font-size:40px}
.bdm__chat{display:flex;flex-direction:column;min-height:0;flex:1;position:relative}.bdm__chat[hidden]{display:none}
.bdm__ch{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--ring)}.bdm__ct{display:flex;flex-direction:column;min-width:0;flex:1}.bdm__ct strong{font-weight:500;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bdm__back{display:none;border:0;background:transparent;font-size:20px;color:var(--ink);cursor:pointer}
.bdm__stream{flex:1;overflow-y:auto;padding:18px 20px;display:flex;flex-direction:column;gap:3px;scroll-behavior:smooth}
.bdm__day{align-self:center;margin:12px 0 8px;color:var(--muted);font-size:12px;background:var(--bg);border:1px solid var(--ring);border-radius:999px;padding:2px 12px}
.bdm__sys{align-self:center;color:var(--muted);font-size:12.5px;margin:8px 0}
.bdm__m{display:flex;gap:9px;max-width:78%;align-items:flex-end}.bdm__m.is-mine{align-self:flex-end;flex-direction:row-reverse}.bdm__m.is-cont .bdm__av{visibility:hidden}.bdm__m.is-cont{margin-top:0}.bdm__m:not(.is-cont){margin-top:10px}
.bdm__m .bdm__av{width:30px;height:30px;font-size:12px}.bdm__m.is-team .bdm__av{background:#14161d}
.bdm__bub{background:var(--neu-bg);border-radius:16px;padding:9px 13px;min-width:60px;position:relative;overflow-wrap:anywhere}.bdm__m.is-mine .bdm__bub{background:var(--accent);color:var(--on);border-bottom-right-radius:5px}.bdm__m:not(.is-mine) .bdm__bub{border-bottom-left-radius:5px}
.bdm__nm{font-size:12px;color:var(--muted);margin:0 0 2px 2px}.bdm__nm b{font-weight:500;color:var(--ink)}.bdm__team{display:inline-block;margin-left:5px;padding:0 6px;border-radius:5px;background:var(--info-bg);color:var(--info);font-size:10.5px;font-weight:600;letter-spacing:.02em}
.bdm__bub a{color:inherit;text-decoration:underline}.bdm__txt{white-space:pre-wrap}
.bdm__meta{font-size:11px;opacity:.65;margin-top:3px;display:flex;gap:8px;justify-content:flex-end;align-items:center}.bdm__del{background:none;border:0;color:inherit;font:inherit;font-size:11px;cursor:pointer;opacity:.8;padding:0;text-decoration:underline}
.bdm__gone{font-style:italic;opacity:.7}
.bdm__files{display:grid;gap:6px;margin-top:6px}.bdm__imgs{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:6px;margin-top:6px}
.bdm__img{display:block;border-radius:10px;overflow:hidden;border:0;padding:0;background:#0001;cursor:zoom-in;aspect-ratio:4/3}.bdm__img img{width:100%;height:100%;object-fit:cover;display:block}
.bdm__f{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;background:#0000000d;color:inherit;text-decoration:none!important}.bdm__m.is-mine .bdm__f{background:#ffffff22}
.bdm__fi{flex:none;width:34px;height:34px;border-radius:8px;background:#0000001a;display:grid;place-items:center;font-size:10px;font-weight:700;letter-spacing:.03em}.bdm__fn{min-width:0;display:flex;flex-direction:column;font-size:13.5px}.bdm__fn span:first-child{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px}.bdm__fn span:last-child{opacity:.65;font-size:12px}
.bdm__seen{align-self:flex-end;color:var(--muted);font-size:11.5px;margin:2px 4px 0}
.bdm__typing{padding:2px 22px 8px;color:var(--muted);font-size:12.5px;display:flex;align-items:center;gap:4px}.bdm__typing[hidden]{display:none}.bdm__typing span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:bdmb 1.2s infinite}.bdm__typing span:nth-child(2){animation-delay:.15s}.bdm__typing span:nth-child(3){animation-delay:.3s}.bdm__typing em{margin-left:6px;font-style:normal}
@keyframes bdmb{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
.bdm__composer{border-top:1px solid var(--ring);padding:12px 14px;position:relative}.bdm__row{display:flex;gap:8px;align-items:flex-end}
.bdm__row textarea{flex:1;resize:none;max-height:160px;border:1px solid var(--ring2);border-radius:12px;padding:10px 13px;background:var(--bg);color:var(--ink);font:inherit;font-size:14.5px;line-height:1.4;outline:0}.bdm__row textarea:focus{border-color:var(--accent)}
.bdm__icon{flex:none;width:42px;height:42px;border-radius:12px;border:1px solid var(--ring2);background:transparent;color:var(--muted);cursor:pointer;display:grid;place-items:center}.bdm__icon:hover{color:var(--ink)}
.bdm__send{height:42px;padding:0 20px}.bdm__send[disabled]{opacity:.5;cursor:default}
.bdm__chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px}.bdm__chips:empty{display:none}
.bdm__chip{display:flex;align-items:center;gap:8px;padding:5px 6px 5px 10px;border:1px solid var(--ring2);border-radius:10px;font-size:13px;max-width:240px;position:relative;overflow:hidden}.bdm__chip span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.bdm__chip button{border:0;background:transparent;color:var(--muted);cursor:pointer;font-size:16px;line-height:1}.bdm__chip img{width:28px;height:28px;border-radius:6px;object-fit:cover}.bdm__chip i{position:absolute;left:0;bottom:0;height:3px;background:var(--accent);width:0}
.bdm__drop{position:absolute;inset:0;background:var(--card);border:2px dashed var(--accent);border-radius:12px;display:none;place-items:center;font-weight:500;opacity:.96}.bdm__composer.is-drag .bdm__drop{display:grid}
.bdm__ro{padding:12px 18px;border-top:1px solid var(--ring);color:var(--warn);background:var(--warn-bg);font-size:13px}
.bdm__dlg{border:1px solid var(--ring);border-radius:14px;padding:22px;width:min(520px,92vw);background:var(--card);color:var(--ink)}.bdm__dlg::backdrop{background:#0008}.bdm__dlg h2{margin:0 0 12px}.bdm__dlg label{display:block;font-size:13px;font-weight:500;margin:10px 0 0}.bdm__dlg input,.bdm__dlg select,.bdm__dlg textarea{display:block;width:100%;margin-top:5px;border:1px solid var(--ring2);border-radius:9px;padding:9px 11px;background:var(--bg);color:var(--ink);font:inherit;font-size:14px}.bdm__dact{display:flex;gap:8px;margin-top:14px;align-items:center}.bdm__err{margin-top:10px;color:var(--bad);background:var(--bad-bg);padding:8px 10px;border-radius:8px;font-size:13px}
.bdm__lb{position:fixed;inset:0;background:#000d;display:grid;place-items:center;z-index:50}.bdm__lb[hidden]{display:none}.bdm__lb img{max-width:92vw;max-height:88vh;border-radius:8px}.bdm__lb button{position:absolute;top:14px;right:18px;border:0;background:transparent;color:#fff;font-size:34px;cursor:pointer}
.bdm__none{padding:30px 14px;text-align:center;color:var(--muted);font-size:14px}
@media (max-width:820px){.bdm{grid-template-columns:1fr;height:calc(100vh - 110px)}.bdm__conv{display:none}.bdm[data-state=chat] .bdm__list{display:none}.bdm[data-state=chat] .bdm__conv{display:flex}.bdm__back{display:block}.bdm__m{max-width:92%}}
CSS;
	}

	private static function js() {
		return <<<'JS'
(function(){
var cfg=JSON.parse(document.getElementById('bdm-cfg').textContent);
var $=function(id){return document.getElementById(id)};
var root=$('bdm'),threadsEl=$('bdm-threads'),stream=$('bdm-stream'),chat=$('bdm-chat'),empty=$('bdm-empty');
var state={status:'open',q:'',cur:0,last:0,otherRead:0,threads:[],busy:false,files:[],lastTyping:0,sending:false};
function api(path,opts){opts=opts||{};var url=cfg.rest.indexOf('?')>-1?cfg.rest+path.replace('?','&'):cfg.rest.replace(/\/$/,'')+path;
 var h={'X-WP-Nonce':cfg.nonce,'Accept':'application/json'};if(opts.json){h['Content-Type']='application/json'}
 return fetch(url,{method:opts.method||'GET',credentials:'same-origin',headers:h,body:opts.json?JSON.stringify(opts.json):opts.body}).then(function(r){return r.json().catch(function(){return null}).then(function(b){if(!r.ok){var e=new Error((b&&b.message)||'Something went wrong ('+r.status+')');e.status=r.status;throw e}return b})})}
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function link(s){return esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g,'<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>')}
function d(t){return new Date(String(t).replace(' ','T')+'Z')}
function hm(t){return d(t).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}
function dayKey(t){var x=d(t);return x.getFullYear()+'-'+x.getMonth()+'-'+x.getDate()}
function dayLabel(t){var x=d(t),n=new Date(),y=new Date(Date.now()-864e5);if(dayKey(t)===n.getFullYear()+'-'+n.getMonth()+'-'+n.getDate())return 'Today';if(dayKey(t)===y.getFullYear()+'-'+y.getMonth()+'-'+y.getDate())return 'Yesterday';return x.toLocaleDateString([],{weekday:'short',day:'numeric',month:'short',year:x.getFullYear()===n.getFullYear()?undefined:'numeric'})}
function ago(t){if(!t)return '';var s=(Date.now()-d(t).getTime())/1000;if(s<60)return 'now';if(s<3600)return Math.floor(s/60)+'m';if(s<86400)return hm(t);if(s<604800)return d(t).toLocaleDateString([],{weekday:'short'});return d(t).toLocaleDateString([],{day:'numeric',month:'short'})}
function ini(n){n=String(n||'?').trim().split(/\s+/);return (n[0][0]+(n[1]?n[1][0]:'')).toUpperCase()}
var COLORS=['#7a5af8','#0e9384','#dd2590','#e04f16','#2970ff','#4e5ba6','#ca8504'];function col(n){var h=0;String(n).split('').forEach(function(c){h=(h*31+c.charCodeAt(0))>>>0});return COLORS[h%COLORS.length]}
function size(b){return b>1048576?(b/1048576).toFixed(1)+' MB':Math.max(1,Math.round(b/1024))+' KB'}
function ext(n){var m=/\.([a-z0-9]+)$/i.exec(n);return m?m[1].slice(0,4).toUpperCase():'FILE'}
// ── list
function renderThreads(){
 if(!state.threads.length){threadsEl.innerHTML='<div class="bdm__none">'+(state.q?'No conversation matches.':state.status==='open'?'No open conversations yet.':'Nothing closed.')+'</div>';return}
 threadsEl.innerHTML=state.threads.map(function(t){var nm=t.client_name||'';return '<button type="button" class="bdm__t'+(t.id===state.cur?' is-on':'')+(t.unread?' is-unread':'')+'" data-id="'+t.id+'"><span class="bdm__av" style="background:'+col(t.subject)+'">'+esc(ini(t.subject))+'</span><span class="bdm__tb"><span class="bdm__tt"><strong>'+esc(t.subject)+'</strong><time>'+esc(ago(t.last_message_at))+'</time></span><span class="bdm__tp">'+(t.last_sender==='client'&&!cfg.isClient?'':'')+esc(t.preview)+'</span>'+(t.context_name?'<span class="bdm__tag">'+esc(t.context_name)+'</span>':'')+'</span>'+(t.unread?'<span class="bdm__dot">'+t.unread+'</span>':'')+'</button>'}).join('')}
function loadThreads(keep){
 var qs='/messages/threads?status='+state.status+(state.q?'&q='+encodeURIComponent(state.q):'')+(cfg.previewAs?'&client_id='+cfg.previewAs:'');
 return api(qs).then(function(r){state.threads=r.threads;renderThreads();badge(r.unread)}).catch(function(){})}
function badge(n){var b=document.getElementById('bdm-nav-badge');if(b){b.textContent=n||'';b.hidden=!n}}
threadsEl.addEventListener('click',function(e){var b=e.target.closest('.bdm__t');if(b)open(+b.dataset.id,true)});
$('bdm-q').addEventListener('input',function(){state.q=this.value;clearTimeout(this._t);this._t=setTimeout(loadThreads,250)});
Array.prototype.forEach.call(document.querySelectorAll('.bdm__tabs button'),function(b){b.addEventListener('click',function(){state.status=b.dataset.st;Array.prototype.forEach.call(b.parentNode.children,function(x){x.classList.toggle('is-on',x===b)});loadThreads()})});
// ── conversation
function msgHtml(m,prev,isLastMine){
 var out='';if(!prev||dayKey(prev.at)!==dayKey(m.at))out+='<div class="bdm__day">'+esc(dayLabel(m.at))+'</div>';
 if(m.kind==='system'){return out+'<div class="bdm__sys">'+esc(m.body)+' · '+esc(hm(m.at))+'</div>'}
 var cont=prev&&prev.kind!=='system'&&prev.side===m.side&&prev.name===m.name&&dayKey(prev.at)===dayKey(m.at)&&(d(m.at)-d(prev.at))<300000;
 var team=m.side==='staff';
 var body=m.deleted?'<div class="bdm__txt bdm__gone">This message was deleted</div>':(m.body?'<div class="bdm__txt">'+link(m.body)+'</div>':'');
 var imgs='',files='';
 (m.files||[]).forEach(function(f){if(f.image)imgs+='<button type="button" class="bdm__img" data-src="'+esc(f.url+'&inline=1')+'" aria-label="View '+esc(f.name)+'"><img loading="lazy" alt="'+esc(f.name)+'" src="'+esc(f.url+'&inline=1')+'"></button>';else files+='<a class="bdm__f" href="'+esc(f.url)+'"><span class="bdm__fi">'+esc(ext(f.name))+'</span><span class="bdm__fn"><span>'+esc(f.name)+'</span><span>'+size(f.size)+'</span></span></a>'});
 var seen=m.mine&&isLastMine&&state.otherRead>=m.id;
 return out+'<div class="bdm__m'+(m.mine?' is-mine':'')+(team?' is-team':'')+(cont?' is-cont':'')+'" data-id="'+m.id+'"><span class="bdm__av" style="background:'+(team?'':col(m.name))+'">'+esc(ini(m.name))+'</span><div>'+(!m.mine&&!cont?'<div class="bdm__nm"><b>'+esc(m.name)+'</b>'+(team?'<span class="bdm__team">TEAM</span>':'')+'</div>':'')+'<div class="bdm__bub">'+body+(imgs?'<div class="bdm__imgs">'+imgs+'</div>':'')+(files?'<div class="bdm__files">'+files+'</div>':'')+'<div class="bdm__meta"><span>'+esc(hm(m.at))+'</span>'+(m.can_delete?'<button type="button" class="bdm__del" data-del="'+m.id+'">Delete</button>':'')+'</div></div>'+(seen?'<div class="bdm__seen">Seen</div>':'')+'</div></div>'}
var cache=[];
function renderAll(){var lastMine=0;cache.forEach(function(m){if(m.mine&&m.kind!=='system')lastMine=m.id});stream.innerHTML=cache.map(function(m,i){return msgHtml(m,cache[i-1],m.id===lastMine)}).join('')}
function atBottom(){return stream.scrollHeight-stream.scrollTop-stream.clientHeight<140}
function toBottom(){stream.scrollTop=stream.scrollHeight}
function open(id,push){
 state.cur=id;state.last=0;cache=[];state.otherRead=0;chat.hidden=false;empty.hidden=true;root.dataset.state='chat';renderThreads();stream.innerHTML='<div class="bdm__none">Loading…</div>';
 if(push&&history.pushState)history.pushState({},'',cfg.base+id+'/'+(cfg.previewAs?'?as='+cfg.previewAs:''));
 return api('/messages/threads/'+id).then(function(r){applyThread(r,true);api('/messages/threads/'+id+'/read',{method:'POST',json:{}}).then(function(x){badge(x.unread);loadThreads()}).catch(function(){})}).catch(function(e){stream.innerHTML='<div class="bdm__none">'+esc(e.message)+'</div>'})}
function applyThread(r,first){
 var t=r.thread;$('bdm-subject').textContent=t.subject;$('bdm-sub').textContent=[t.context_name,t.client_name&&cfg.orgs.length>1?t.client_name:''].filter(Boolean).join(' · ')||'';
 $('bdm-status').hidden=t.status!=='closed';state.otherRead=r.other_read;
 var stick=first||atBottom();
 if(first){cache=[]}
 r.messages.forEach(function(m){var i=cache.findIndex(function(x){return x.id===m.id});if(i>-1)cache[i]=m;else cache.push(m);state.last=Math.max(state.last,m.id)});
 // keep deletions in sync
 renderAll();if(stick)toBottom();
 var ty=$('bdm-typing');ty.hidden=!r.typing;$('bdm-typing-t').textContent=r.typing?r.typing+' is typing…':'';badge(r.unread_total);return r}
function poll(){if(!state.cur||document.hidden)return;api('/messages/threads/'+state.cur+(state.last?'?after='+state.last:'')).then(function(r){var had=r.messages.length;if(had||r.typing||true){var firstChange=had>0;applyThread(r,false);if(firstChange){api('/messages/threads/'+state.cur+'/read',{method:'POST',json:{}}).then(function(x){badge(x.unread)}).catch(function(){})}}}).catch(function(){})}
setInterval(poll,4000);setInterval(function(){if(!document.hidden)loadThreads()},15000);
document.addEventListener('visibilitychange',function(){if(!document.hidden){poll();loadThreads()}});
$('bdm-back').addEventListener('click',function(){root.dataset.state='list';state.cur=0;chat.hidden=true;empty.hidden=false;renderThreads();if(history.pushState)history.pushState({},'',cfg.base+(cfg.previewAs?'?as='+cfg.previewAs:''))});
window.addEventListener('popstate',function(){var m=/messages\/(\d+)/.exec(location.pathname);if(m)open(+m[1],false);else{root.dataset.state='list';state.cur=0;chat.hidden=true;empty.hidden=false;renderThreads()}});
stream.addEventListener('click',function(e){var im=e.target.closest('.bdm__img');if(im){var lb=$('bdm-lb');lb.querySelector('img').src=im.dataset.src;lb.hidden=false;return}
 var del=e.target.closest('[data-del]');if(del&&confirm('Delete this message?')){api('/messages/messages/'+del.dataset.del,{method:'DELETE'}).then(function(){state.last=0;return api('/messages/threads/'+state.cur)}).then(function(r){applyThread(r,true)}).catch(function(x){alert(x.message)})}});
$('bdm-lb').addEventListener('click',function(){this.hidden=true});document.addEventListener('keydown',function(e){if(e.key==='Escape')$('bdm-lb').hidden=true});
// ── composing
var form=$('bdm-form');
function chipsHtml(list,el){el.innerHTML=list.map(function(f,i){return '<div class="bdm__chip">'+(f.preview?'<img alt="" src="'+f.preview+'">':'')+'<span>'+esc(f.file.name)+' · '+size(f.file.size)+'</span><button type="button" data-rm="'+i+'" aria-label="Remove">×</button></div>'}).join('')}
function addFiles(list,target,chipsEl,errEl){Array.prototype.forEach.call(list,function(f){
 var e=(f.name.split('.').pop()||'').toLowerCase();var msg='';
 if(cfg.allowed.indexOf(e)<0)msg='“'+f.name+'” is not an allowed file type.';else if(f.size>cfg.maxBytes)msg='“'+f.name+'” is larger than '+size(cfg.maxBytes)+'.';else if(target.length>=10)msg='You can attach up to 10 files.';
 if(msg){(errEl||{}).textContent=msg;if(errEl){errEl.hidden=false}else{flash(msg)}return}
 if(errEl)errEl.hidden=true;var o={file:f};if(/^image\//.test(f.type)&&f.size<8e6){o.preview=URL.createObjectURL(f)}target.push(o)});chipsHtml(target,chipsEl)}
function flash(m){var c=$('bdm-chips');var x=document.createElement('div');x.className='bdm__err';x.textContent=m;c.parentNode.insertBefore(x,c);setTimeout(function(){x.remove()},4000)}
if(form){
 var ta=$('bdm-text'),chips=$('bdm-chips');
 function grow(){ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,160)+'px'}
 ta.addEventListener('input',function(){grow();if(state.cur&&Date.now()-state.lastTyping>3000){state.lastTyping=Date.now();api('/messages/threads/'+state.cur+'/typing',{method:'POST',json:{}}).catch(function(){})}});
 ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();form.requestSubmit()}});
 ta.addEventListener('paste',function(e){var fs=[];Array.prototype.forEach.call((e.clipboardData&&e.clipboardData.files)||[],function(f){fs.push(f)});if(fs.length){e.preventDefault();addFiles(fs,state.files,chips)}});
 $('bdm-attach').addEventListener('click',function(){$('bdm-file').click()});
 $('bdm-file').addEventListener('change',function(){addFiles(this.files,state.files,chips);this.value=''});
 chips.addEventListener('click',function(e){var b=e.target.closest('[data-rm]');if(b){state.files.splice(+b.dataset.rm,1);chipsHtml(state.files,chips)}});
 ['dragenter','dragover'].forEach(function(ev){chat.addEventListener(ev,function(e){if(e.dataTransfer&&Array.prototype.indexOf.call(e.dataTransfer.types,'Files')>-1){e.preventDefault();form.classList.add('is-drag')}})});
 ['dragleave','drop'].forEach(function(ev){chat.addEventListener(ev,function(e){if(ev==='drop'||e.target===chat||e.target.id==='bdm-drop'){form.classList.remove('is-drag')}if(ev==='drop'){e.preventDefault();addFiles(e.dataTransfer.files,state.files,chips)}})});
 form.addEventListener('submit',function(e){e.preventDefault();if(state.sending||!state.cur)return;var text=ta.value.trim();if(!text&&!state.files.length)return;
  state.sending=true;$('bdm-send').disabled=true;var fd=new FormData();fd.append('body',text);state.files.forEach(function(f){fd.append('files[]',f.file)});
  send('/messages/threads/'+state.cur+'/messages',fd,chips).then(function(){ta.value='';grow();state.files=[];chipsHtml([],chips);return api('/messages/threads/'+state.cur+(state.last?'?after='+state.last:''))}).then(function(r){applyThread(r,false);toBottom();loadThreads()}).catch(function(x){flash(x.message)}).then(function(){state.sending=false;$('bdm-send').disabled=false;ta.focus()})})
}
function send(path,fd,chipsEl){return new Promise(function(res,rej){var x=new XMLHttpRequest();var url=cfg.rest.indexOf('?')>-1?cfg.rest+path.replace('?','&'):cfg.rest.replace(/\/$/,'')+path;x.open('POST',url);x.setRequestHeader('X-WP-Nonce',cfg.nonce);x.withCredentials=true;
 x.upload.onprogress=function(e){if(e.lengthComputable&&chipsEl){var p=Math.round(e.loaded/e.total*100);Array.prototype.forEach.call(chipsEl.querySelectorAll('.bdm__chip'),function(c){var i=c.querySelector('i');if(!i){i=document.createElement('i');c.appendChild(i)}i.style.width=p+'%'})}};
 x.onload=function(){var b=null;try{b=JSON.parse(x.responseText)}catch(e){}if(x.status>=200&&x.status<300)res(b);else rej(new Error((b&&b.message)||'Could not send ('+x.status+')'))};x.onerror=function(){rej(new Error('Network problem. Please try again.'))};x.send(fd)})}
// ── new conversation
var dlg=$('bdm-dlg'),nfiles=[];
if($('bdm-new')){
 function loadProjects(){var cid=$('bdm-norg')?+$('bdm-norg').value:cfg.client;api('/messages/contexts?client_id='+cid).then(function(p){$('bdm-nproj').innerHTML='<option value="0">General question</option>'+p.map(function(x){return '<option value="'+x.value+'">'+esc(x.name)+'</option>'}).join('')}).catch(function(){})}
 $('bdm-new').addEventListener('click',function(){nfiles=[];chipsHtml(nfiles,$('bdm-nchips'));$('bdm-nsub').value='';$('bdm-nbody').value='';$('bdm-nerr').hidden=true;loadProjects();dlg.showModal();$('bdm-nsub').focus()});
 if($('bdm-norg'))$('bdm-norg').addEventListener('change',loadProjects);
 $('bdm-ncancel').addEventListener('click',function(){dlg.close()});$('bdm-nattach').addEventListener('click',function(){$('bdm-nfile').click()});
 $('bdm-nfile').addEventListener('change',function(){addFiles(this.files,nfiles,$('bdm-nchips'),$('bdm-nerr'));this.value=''});
 $('bdm-nchips').addEventListener('click',function(e){var b=e.target.closest('[data-rm]');if(b){nfiles.splice(+b.dataset.rm,1);chipsHtml(nfiles,$('bdm-nchips'))}});
 $('bdm-nform').addEventListener('submit',function(e){e.preventDefault();var body=$('bdm-nbody').value.trim();if(!body&&!nfiles.length){$('bdm-nerr').textContent='Write a message or attach a file.';$('bdm-nerr').hidden=false;return}
  $('bdm-ncreate').disabled=true;var fd=new FormData();fd.append('body',body);fd.append('subject',$('bdm-nsub').value);fd.append('context',$('bdm-nproj').value);fd.append('client_id',$('bdm-norg')?$('bdm-norg').value:cfg.client);nfiles.forEach(function(f){fd.append('files[]',f.file)});
  send('/messages/threads',fd,$('bdm-nchips')).then(function(r){dlg.close();state.status='open';return loadThreads().then(function(){return open(r.thread_id,true)})}).catch(function(x){$('bdm-nerr').textContent=x.message;$('bdm-nerr').hidden=false}).then(function(){$('bdm-ncreate').disabled=false})})
}
loadThreads().then(function(){if(cfg.thread)open(cfg.thread,false)});
})();
JS;
	}
}
