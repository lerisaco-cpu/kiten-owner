<?php
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/layout.php';
require __DIR__ . '/lib/logfiles.php';

// 条件の解釈・取得・行の描画は API 側と共通。定数を先に定義して、
// api_shops_more.php を「関数の定義だけ」で読み込む。
define('SHOPS_LIST_INCLUDE', true);
require __DIR__ . '/api_shops_more.php';

require_login();

$perPage      = (int)cfg('per_page', 50);
$canEditMeta  = can_edit();   // 一覧のインライン編集を出すかどうか

/*
 * 画面を開いたときの初期値。
 *   契約状況 … 契約中（shops_params() が既にそうしている）
 *   媒体     … ヘブン
 *   システム異常 … 異常あり（いずれか）
 *
 * あくまで初期値なので、URL に指定があればそちらが優先されます。
 * 「未指定」と「すべてを明示的に選んだ」を取り違えないよう、指定の有無は
 * array_key_exists() で見ます。値が空かどうかでは判断しません。
 *
 * 「すべて」は内部では空文字ですが、空文字のままだと url_with() で落ちてしまい、
 * 並び替えや日付の切り替えで初期値に戻ってしまいます。そのため URL 上は 'all'
 * という値でやり取りし、ここで空文字に戻します（契約状況の STATUS_ALL と同じ考え方）。
 */
const LIST_ALL = 'all';
$listDefaults = ['media' => 'heaven', 'issue' => 'any'];

$get = $_GET;
foreach ($listDefaults as $key => $fallback) {
    if (!array_key_exists($key, $get)) {
        $get[$key] = $fallback;          // 未指定 → 初期値
    } elseif ((string)$get[$key] === LIST_ALL) {
        $get[$key] = '';                 // 明示的な「すべて」 → 絞り込みなし
    }
}

$p      = shops_params($get);
$date   = $p['date'];
$kw     = $p['kw'];
$status = $p['status'];
$media  = $p['media'];
$issue  = $p['issue'];

// URL やフォームに載せるときの値。「すべて」だけ 'all' に置き換える。
$mediaParam = $media === '' ? LIST_ALL : $media;
$issueParam = $issue === '' ? LIST_ALL : $issue;

$issueOptions = shops_issue_options();
$mediaOptions = shops_media_options();

/*
 * 最初の1画面分。
 *
 * 読むのは status / report / loginerr の3つだけで、mitene_report と okini_report は
 * 開かない（ミテネは1日1,300行を超えることがあるため）。status も START行・FINISHの有無・
 * 最終行だけを1回流して拾い、全行を配列に貯めない。
 *
 * 続きはスクロールに合わせて api_shops_more.php から取る。そのとき起点になるのが
 * $dbOffset（SQL から何行読んだか）で、追加読み込みでは必要な範囲しか読まない。
 *
 * 総件数は、システム異常で絞らないときは COUNT(*)。絞るときだけログを読んで数えるが、
 * それはこの初回表示の1回だけで、追加読み込みでは行わない。
 */
$total    = shops_total($p);
$first    = shops_fetch($p, 0, $perPage);
$rows     = $first['rows'];
$dbOffset = $first['consumed'];
$hasMore  = !$first['exhausted'];

function sort_link(string $key, string $labelText): string
{
    $cur = $_GET['sort'] ?? 'id';
    $dir = ($cur === $key && ($_GET['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc';
    $mark = $cur === $key ? (($_GET['dir'] ?? 'asc') === 'asc' ? ' <i class="fa-solid fa-caret-up"></i>' : ' <i class="fa-solid fa-caret-down"></i>') : '';
    return '<a href="' . h(url_with(['sort' => $key, 'dir' => $dir])) . '">' . h($labelText) . $mark . '</a>';
}

// 日付の候補。全店舗のフォルダを走査すると重いので、直近30営業日を並べる。
$dateOptions = [];
for ($i = 0; $i < 30; $i++) {
    $dateOptions[] = log_date_shift(log_business_date(), -$i);
}
if (!in_array($date, $dateOptions, true)) {
    array_unshift($dateOptions, $date);
}

render_head('店舗管理', 'shops');
?>

<form class="filter-card" method="get" action="shops.php">
    <?php /* 検索し直しても、選んでいる営業日を保つ */ ?>
    <input type="hidden" name="date" value="<?= h($date) ?>">
    <div class="filter-row">
        <div class="filter-field">
            <label for="kw">店舗名</label>
            <input type="text" id="kw" name="kw" value="<?= h($kw) ?>" placeholder="部分一致検索">
        </div>
        <div class="filter-field">
            <label for="status">契約状況</label>
            <select id="status" name="status">
                <option value="<?= STATUS_ALL ?>"<?= $status === STATUS_ALL ? ' selected' : '' ?>>すべて</option>
                <?php foreach ((array)cfg('status_labels', []) as $v => $lbl): ?>
                    <option value="<?= (int)$v ?>"<?= $status !== STATUS_ALL && (int)$status === (int)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="media">媒体</label>
            <select id="media" name="media">
                <?php foreach ($mediaOptions as $v => $lbl): ?>
                    <option value="<?= h((string)$v === '' ? LIST_ALL : (string)$v) ?>"<?= $media === (string)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label for="issue">システム異常</label>
            <select id="issue" name="issue">
                <?php foreach ($issueOptions as $v => $lbl): ?>
                    <option value="<?= h((string)$v === '' ? LIST_ALL : (string)$v) ?>"<?= $issue === (string)$v ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-main" type="submit"><i class="fa-solid fa-magnifying-glass"></i>検索</button>
            <a class="btn btn-outline" href="shops.php"><i class="fa-solid fa-rotate-left"></i>リセット</a>
        </div>
    </div>
</form>

<div class="date-nav">
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, -1)])) ?>">
        <i class="fa-solid fa-chevron-left"></i>前日
    </a>
    <form method="get" action="shops.php" class="date-nav-form">
        <?php /* 日付だけを変えて、検索条件は保つ */ ?>
        <?php if ($kw !== ''): ?><input type="hidden" name="kw" value="<?= h($kw) ?>"><?php endif; ?>
        <input type="hidden" name="status" value="<?= h($status) ?>">
        <?php /* 「すべて」も含めて必ず持たせる。落とすと初期値に戻ってしまう。 */ ?>
        <input type="hidden" name="media" value="<?= h($mediaParam) ?>">
        <input type="hidden" name="issue" value="<?= h($issueParam) ?>">
        <select name="date" onchange="this.form.submit()">
            <?php foreach ($dateOptions as $d): ?>
                <option value="<?= h($d) ?>"<?= $d === $date ? ' selected' : '' ?>><?= h(log_date_label($d)) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-outline btn-sm" type="submit">表示</button></noscript>
    </form>
    <a class="btn btn-outline btn-sm" href="<?= h(url_with(['date' => log_date_shift($date, 1)])) ?>">
        翌日<i class="fa-solid fa-chevron-right"></i>
    </a>
    <span class="date-nav-current"><?= h(log_date_label($date)) ?> の営業分のログで判定しています</span>
</div>

<div class="section-card">
    <div class="head">
        <i class="fa-solid fa-users"></i>店舗一覧
        <?php if ($canEditMeta): ?>
            <span class="head-hint">フォルダ名 / IP はダブルクリックで編集できます</span>
        <?php endif; ?>
        <span class="count">全 <?= number_format($total) ?> 件</span>
    </div>
    <?php if (!$rows): ?>
        <div class="empty"><i class="fa-solid fa-magnifying-glass"></i>条件に合う店舗はありません。条件を変えて検索してください。</div>
    <?php else: ?>
    <div class="table-scroll">
    <table class="data-table" id="shop-table"<?= $canEditMeta ? ' data-csrf="' . h(csrf_token()) . '"' : '' ?>>
        <thead>
        <tr>
            <th style="width:64px"><?= sort_link('id', 'ID') ?></th>
            <th style="width:140px"><?= sort_link('name', '店舗名') ?></th>
            <th style="width:260px">ログインID</th>
            <th style="width:170px">フォルダ名</th>
            <th style="width:110px"><?= sort_link('girls', 'キャスト') ?></th>
            <th style="width:90px"><?= sort_link('status', '契約') ?></th>
            <th style="width:310px">システム異常</th>
            <th style="width:110px">ログイン失敗</th>
            <th style="width:150px"></th>
        </tr>
        </thead>
        <tbody id="shop-rows">
        <?php shops_render_rows($rows, $date, $canEditMeta); ?>
        </tbody>
    </table>
    </div>
    <div class="infinite-foot"
         id="shop-more"
         data-total="<?= (int)$total ?>"
         data-loaded="<?= count($rows) ?>"
         data-offset="<?= (int)$dbOffset ?>"
         data-more="<?= $hasMore ? '1' : '0' ?>"
         data-query="<?= h(http_build_query([
             'date'   => $date,
             'kw'     => $kw,
             'status' => $status,
             'media'  => $media,
             'issue'  => $issue,
             'sort'   => $p['sort'],
             'dir'    => $p['dir'],
         ])) ?>">
        <div class="infinite-status">
            <span id="shop-count">全 <?= number_format($total) ?> 件中 <?= number_format(count($rows)) ?> 件を表示</span>
            <span class="infinite-spinner" id="shop-spinner" hidden>
                <i class="fa-solid fa-circle-notch fa-spin"></i>読み込み中…
            </span>
            <span class="infinite-done" id="shop-done"<?= $hasMore ? ' hidden' : '' ?>>
                <i class="fa-solid fa-check"></i>すべて表示しました
            </span>
        </div>
        <?php if ($hasMore): ?>
            <?php /* スクロールで自動的に読み込むが、押しても読み込めるようにしておく */ ?>
            <button type="button" class="btn btn-outline btn-sm" id="shop-more-btn">続きを読み込む</button>
        <?php endif; ?>
        <div class="infinite-error" id="shop-error" hidden></div>
    </div>
    <?php endif; ?>
</div>

<?php /* 画面の隅に出す通知。position:fixed なので表のレイアウトには影響しない。 */ ?>
<div class="toast-area" id="toast-area"></div>

<script>
(function () {
    /*
     * 下までスクロールしたら続きを読み込む。
     *
     * スクロールイベントは拾わず、表の下に置いた受け皿を IntersectionObserver で
     * 見張る。読み込み中は次の要求を出さないので、二重には読み込まれない。
     *
     * 行は tbody に足すだけで、クリックやダブルクリックの処理は表全体に付けて
     * あるため、あとから増えた行でもそのまま動く。
     */
    var foot = document.getElementById('shop-more');
    var body = document.getElementById('shop-rows');
    if (!foot || !body) { return; }

    var countEl   = document.getElementById('shop-count');
    var spinner   = document.getElementById('shop-spinner');
    var doneEl    = document.getElementById('shop-done');
    var errorEl   = document.getElementById('shop-error');
    var moreBtn   = document.getElementById('shop-more-btn');

    var total    = parseInt(foot.dataset.total, 10) || 0;
    var loaded   = parseInt(foot.dataset.loaded, 10) || 0;
    var dbOffset = parseInt(foot.dataset.offset, 10) || 0;
    var hasMore  = foot.dataset.more === '1';
    var query    = foot.dataset.query || '';
    var busy     = false;

    function paint() {
        countEl.textContent = '全 ' + total.toLocaleString() + ' 件中 '
                            + loaded.toLocaleString() + ' 件を表示';
        doneEl.hidden = hasMore;
        if (moreBtn) { moreBtn.hidden = !hasMore; }
    }

    function finish() {
        hasMore = false;
        if (observer) { observer.disconnect(); }
        paint();
    }

    function load() {
        if (busy || !hasMore) { return; }
        busy = true;
        spinner.hidden = false;
        errorEl.hidden = true;
        if (moreBtn) { moreBtn.disabled = true; }

        fetch('api_shops_more.php?' + query + '&db_offset=' + dbOffset, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) { /* JSON以外はそのまま理由にする */ }
                if (!res.ok || !data || !data.ok) {
                    // サーバーが返した理由をそのまま利用者に見せる
                    var err = new Error(
                        (data && data.error) ||
                        text.trim().slice(0, 200) ||
                        ('続きを読み込めませんでした（HTTP ' + res.status + '）。')
                    );
                    err.fromServer = true;
                    throw err;
                }
                return data;
            });
        }).then(function (data) {
            if (data.html) {
                body.insertAdjacentHTML('beforeend', data.html);
            }
            loaded  += data.count;
            dbOffset = data.db_offset;
            hasMore  = !!data.more && data.count > 0;
            busy = false;
            spinner.hidden = true;
            if (moreBtn) { moreBtn.disabled = false; }
            if (!hasMore) { finish(); } else { paint(); }
            // 受け皿がまだ画面内なら、続けて次を読む
            if (hasMore && observer && foot.getBoundingClientRect().top < window.innerHeight) {
                load();
            }
        }).catch(function (err) {
            busy = false;
            spinner.hidden = true;
            if (moreBtn) { moreBtn.disabled = false; }
            // 通信そのものが失敗した場合、err.message はブラウザ既定の英文なので出さない
            errorEl.textContent = (err && err.fromServer && err.message)
                ? err.message
                : '続きを読み込めませんでした。通信の状態を確認して、もう一度お試しください。';
            errorEl.hidden = false;
        });
    }

    var observer = null;
    if (window.IntersectionObserver) {
        observer = new IntersectionObserver(function (entries) {
            if (entries.some(function (x) { return x.isIntersecting; })) { load(); }
        }, { rootMargin: '200px 0px' });   // 下端に近づいた時点で先に取りにいく
        observer.observe(foot);
    }
    if (moreBtn) { moreBtn.addEventListener('click', load); }

    paint();
})();
</script>

<script>
(function () {
    // 店舗名は1行に収めて「…」で切っているので、切れているものだけ hover で全体を出す。
    // 行は後から追加されるので、表全体で受けて必要になったときに title を付ける。
    var table = document.getElementById('shop-table');
    if (!table) { return; }

    table.addEventListener('mouseover', function (e) {
        var el = e.target.closest('#shop-rows td:nth-child(2) .strong');
        if (!el || el.hasAttribute('title')) { return; }
        if (el.scrollWidth > el.clientWidth) {
            el.setAttribute('title', el.textContent.trim());
        }
    });
})();
</script>

<script>
(function () {
    // ログイン失敗のバッジを押すと、そのキャストを停止する SQL をコピーする。
    // SQL は data-sql に入れて出力済みなので、押したときにサーバーへは問い合わせない。
    var table = document.getElementById('shop-table');
    if (!table) { return; }

    var area = document.getElementById('toast-area');

    /**
     * 成功したことを、バッジの色と画面隅の通知で伝える。
     * 文字を書き換えると幅が変わって列がずれるので、中身には触らない。
     */
    function flash(btn) {
        btn.classList.add('is-copied');
        clearTimeout(btn.dataset.timer);
        btn.dataset.timer = setTimeout(function () {
            btn.classList.remove('is-copied');
        }, 2500);
    }

    function toast(msg) {
        if (!area) { return; }
        var box = document.createElement('div');
        box.className = 'alert-box alert-ok';
        box.textContent = msg;
        area.appendChild(box);
        setTimeout(function () { box.remove(); }, 3500);
    }

    function done(btn, sql) {
        flash(btn);
        toast('SQL ' + sql.split('\n').length + ' 文をコピーしました');
    }

    /** Clipboard API が使えない環境向け。手で選んでコピーしてもらう。 */
    function manual(sql) {
        var old = document.getElementById('sql-fallback');
        if (old) { old.remove(); }

        var box = document.createElement('div');
        box.id = 'sql-fallback';
        box.className = 'sql-fallback';

        var head = document.createElement('div');
        head.className = 'sql-fallback-head';
        head.textContent = '自動でコピーできませんでした。下の内容を選んでコピーしてください。';

        var ta = document.createElement('textarea');
        ta.readOnly = true;
        ta.wrap = 'off';
        ta.rows = Math.min(8, sql.split('\n').length + 1);
        ta.value = sql;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn btn-outline btn-sm';
        close.textContent = '閉じる';
        close.addEventListener('click', function () { box.remove(); });

        box.appendChild(head);
        box.appendChild(ta);
        box.appendChild(close);
        document.body.appendChild(box);
        ta.focus();
        ta.select();
    }

    table.addEventListener('click', function (e) {
        var btn = e.target.closest('.sql-copy-badge');
        if (!btn) { return; }
        var sql = btn.getAttribute('data-sql') || '';
        if (sql === '') { return; }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(sql).then(
                function () { done(btn, sql); },
                function () { manual(sql); }
            );
            return;
        }

        // https でない環境では clipboard API が使えないので、従来の方法を試す
        var tmp = document.createElement('textarea');
        tmp.value = sql;
        tmp.setAttribute('readonly', '');
        tmp.style.position = 'fixed';
        tmp.style.top = '-1000px';
        document.body.appendChild(tmp);
        tmp.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        tmp.remove();
        if (ok) { done(btn, sql); } else { manual(sql); }
    });
})();
</script>

<?php if ($canEditMeta): ?>
<script>
(function () {
    var table = document.getElementById('shop-table');
    if (!table) { return; }
    var csrf = table.getAttribute('data-csrf');
    var area = document.getElementById('toast-area');   // 上のブロックと同じ場所を使う

    /** 右下に数秒だけ出す通知。保存の失敗理由をここに出す。 */
    function toast(msg, type) {
        var box = document.createElement('div');
        box.className = 'alert-box alert-' + type;
        box.textContent = msg;
        area.appendChild(box);
        setTimeout(function () { box.remove(); }, 6000);
    }

    /** セルを通常の表示状態に戻す。値は data-value が正。 */
    function render(td) {
        var v = td.getAttribute('data-value');
        td.textContent = '';
        if (v === '') {
            var s = document.createElement('span');
            s.className = 'soft';
            s.textContent = '—';
            td.appendChild(s);
        } else {
            td.appendChild(document.createTextNode(v));
        }
    }

    function flash(td, cls) {
        td.classList.add(cls);
        setTimeout(function () { td.classList.remove(cls); }, 2000);
    }

    function beginEdit(td) {
        if (td.classList.contains('is-editing') || td.classList.contains('is-saving')) { return; }
        td.classList.add('is-editing');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'meta-input';
        input.value = td.getAttribute('data-value');
        input.maxLength = parseInt(td.getAttribute('data-max'), 10) || 191;
        td.textContent = '';
        td.appendChild(input);
        input.focus();
        input.select();

        var done = false;
        function finish(save) {
            if (done) { return; }
            done = true;
            var next = input.value;
            td.classList.remove('is-editing');
            if (!save || next === td.getAttribute('data-value')) {
                render(td);           // Esc、または変更なし
                return;
            }
            commit(td, next);
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); finish(true); }
            else if (e.key === 'Escape') { e.preventDefault(); finish(false); }
        });
        input.addEventListener('blur', function () { finish(true); });
    }

    function commit(td, value) {
        td.classList.add('is-saving');
        td.textContent = value === '' ? '—' : value;

        var body = new URLSearchParams();
        body.set('_csrf', csrf);
        body.set('user_id', td.getAttribute('data-id'));
        body.set('field', td.getAttribute('data-field'));
        body.set('value', value);

        fetch('api_shop_meta.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (e) { /* JSON以外はそのまま理由として扱う */ }
                if (!res.ok || !data || !data.ok) {
                    // サーバーが返した理由をそのまま利用者に見せる
                    var err = new Error(
                        (data && data.error) ||
                        text.trim().slice(0, 200) ||
                        ('保存できませんでした（HTTP ' + res.status + '）。')
                    );
                    err.fromServer = true;
                    throw err;
                }
                return data;
            });
        }).then(function (data) {
            td.classList.remove('is-saving');
            td.setAttribute('data-value', data.value);   // 桁数を超えた分はサーバー側で切られる
            render(td);
            flash(td, 'is-saved');
        }).catch(function (err) {
            td.classList.remove('is-saving');
            render(td);                                  // data-value は変えていないので元の値に戻る
            flash(td, 'is-error');
            // 通信そのものが失敗した場合、err.message はブラウザ既定の英文なので出さない
            toast(
                (err && err.fromServer && err.message)
                    ? err.message
                    : '保存できませんでした。通信の状態を確認して、もう一度お試しください。',
                'bad'
            );
        });
    }

    table.addEventListener('dblclick', function (e) {
        var td = e.target.closest('td.meta-cell');
        if (td) { beginEdit(td); }
    });
})();
</script>
<?php endif; ?>

<?php render_foot(); ?>
