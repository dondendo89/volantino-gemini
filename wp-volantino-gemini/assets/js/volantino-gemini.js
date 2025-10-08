(function($){
    function fetchSupermarkets(){
        // Usa solo i supermercati presenti nel DB del plugin, iniettati dal PHP (window.VG_SUPERMARKETS)
        const list = Array.isArray(window.VG_SUPERMARKETS) ? window.VG_SUPERMARKETS : [];
        if (!list.length && window.VG_DEFAULT_SUPERMARKET) { list.push(window.VG_DEFAULT_SUPERMARKET); }
        // Restituisce una Promise compatibile con .done()/.fail()
        const dfd = $.Deferred();
        dfd.resolve(list);
        return dfd.promise();
    }
    function fetchProducts(params){
        // Chiamata diretta all'API /products con i parametri di filtro/paginazione
        const query = Object.assign({ page: 1, page_size: 20 }, params || {});
        // Rimuove parametri vuoti ("", null, undefined) per evitare errori di parsing lato API
        const sanitized = {};
        Object.keys(query).forEach(function(k){
            const v = query[k];
            if (v !== '' && v !== null && v !== undefined) { sanitized[k] = v; }
        });
        return $.ajax({
            url: window.VG_API_BASE + '/products',
            method: 'GET',
            data: sanitized,
            dataType: 'json'
        });
    }
    function getCart(){
        try { return JSON.parse(localStorage.getItem('vg_cart')||'[]'); } catch(e){ return []; }
    }
    function setCart(items){
        localStorage.setItem('vg_cart', JSON.stringify(items||[]));
    }
    function addToCart(p){
        const cart = getCart();
        const id = p.id || (p.nome + '|' + (p.marca||'') + '|' + (p.supermercato||''));
        const existing = cart.find(i => i.id === id);
        if (existing){ existing.qty += 1; }
        else {
            cart.push({ id, nome: p.nome||'Prodotto', marca: p.marca||'', prezzo: p.prezzo||'', supermercato: p.supermercato||'', immagine: p.immagine_prodotto_card||'', qty: 1 });
        }
        setCart(cart);
        renderCart($('#vg-shopping-list'), $('#vg-shopping-total'));
    }
    function removeFromCart(id){
        const cart = getCart().filter(i => i.id !== id);
        setCart(cart);
        renderCart($('#vg-shopping-list'), $('#vg-shopping-total'));
    }
    function changeQty(id, delta){
        const cart = getCart();
        const item = cart.find(i => i.id === id);
        if (!item) return;
        item.qty = Math.max(1, item.qty + delta);
        setCart(cart);
        renderCart($('#vg-shopping-list'), $('#vg-shopping-total'));
    }
    function formatTotal(cart){
        let sum = 0;
        cart.forEach(i => {
            const m = (i.prezzo||'').match(/([0-9]+[\.,][0-9]+)/);
            if (m){ sum += parseFloat(m[1].replace(',', '.')) * (i.qty||1); }
        });
        return 'Totale stimato: € ' + sum.toFixed(2);
    }
    function renderCart(listEl, totalEl){
        const cart = getCart();
        if (!listEl || !totalEl) return;
        if (!cart.length){ listEl.html('<p>Nessun elemento nella lista.</p>'); totalEl.text(''); return; }
        const html = cart.map(i => {
            const img = i.immagine ? (window.VG_API_BASE + '/images/' + String(i.immagine).replace(/^[\\/]+/, '')) : '';
            return '<div class="vg-cart-item">'
                + (img ? ('<img src="'+img+'" alt="'+(i.nome||'')+'" />') : '')
                + '<div class="vg-cart-info">'
                    + '<div class="vg-cart-title">' + (i.nome||'Prodotto') + '</div>'
                    + '<div class="vg-cart-meta">' + (i.marca||'') + (i.prezzo? ' • '+i.prezzo : '') + '</div>'
                    + '<div class="vg-cart-actions">'
                        + '<button class="button vg-cart-dec" data-id="'+i.id+'">-</button>'
                        + '<span class="vg-cart-qty">'+i.qty+'</span>'
                        + '<button class="button vg-cart-inc" data-id="'+i.id+'">+</button>'
                        + '<button class="button vg-cart-remove" data-id="'+i.id+'">Rimuovi</button>'
                    + '</div>'
                + '</div>'
            + '</div>';
        }).join('');
        listEl.html(html);
        totalEl.text(formatTotal(cart));
    }
    function renderProducts(container, data){
        const list = data && data.products ? data.products : [];
        const html = list.map(p => {
            const img = p.immagine_prodotto_card ? (window.VG_API_BASE + '/images/' + p.immagine_prodotto_card.replace(/^[\\/]+/, '')) : '';
            return '<div class="vg-card">' +
                (img ? ('<img src="' + img + '" alt="'+ (p.nome || '') +'" />') : '') +
                '<div class="vg-title">' + (p.nome || 'Prodotto') + '</div>' +
                '<div class="vg-meta">' +
                    (p.marca ? ('<span>Marca: ' + p.marca + '</span> ') : '') +
                    (p.categoria ? ('<span>Categoria: ' + p.categoria + '</span> ') : '') +
                    (p.supermercato ? ('<span>Supermercato: ' + p.supermercato + '</span> ') : '') +
                '</div>' +
                (p.prezzo ? ('<div class="vg-price">' + p.prezzo + '</div>') : '') +
                '<div class="vg-actions">'
                    + '<button class="button vg-add" data-item="'+ encodeURIComponent(JSON.stringify(p)) +'">Aggiungi alla lista</button>'
                + '</div>' +
            '</div>';
        }).join('');
        container.html(html || '<p>Nessun prodotto trovato.</p>');
        container.find('.vg-add').off('click').on('click', function(){
            const p = JSON.parse(decodeURIComponent($(this).data('item')));
            addToCart(p);
        });
    }

    $(function(){
        const $select = $('#vg-supermarket');
        const $search = $('#vg-search');
        const $list = $('#vg-products-list');
        const $prev = $('#vg-prev');
        const $next = $('#vg-next');
        const $pageInfo = $('#vg-page-info');
        let page = 1;
        const pageSize = 20;
        let currentSupermarket = window.VG_DEFAULT_SUPERMARKET || '';
        let currentQuery = '';

        function update(){
            fetchProducts({ page: page, page_size: pageSize, supermarket: currentSupermarket, q: currentQuery })
                .done(function(data){
                    renderProducts($list, data);
                    const total = (data && data.total) ? data.total : 0;
                    const pageCount = Math.ceil(total / pageSize) || 1;
                    $prev.prop('disabled', page <= 1);
                    $next.prop('disabled', page >= pageCount);
                    $pageInfo.text('Pagina ' + page + ' / ' + pageCount);
                })
                .fail(function(){
                    $list.html('<p>Errore di rete.</p>');
                    $prev.prop('disabled', true);
                    $next.prop('disabled', true);
                });
        }

        fetchSupermarkets().done(function(markets){
            const options = (markets||[]).map(m => '<option value="'+ m +'"'+ (m===currentSupermarket? ' selected':'') +'>'+ m +'</option>').join('');
            $select.html(options || '<option>' + (currentSupermarket || 'Seleziona') + '</option>');
            update();
        }).fail(function(){
            $select.html('<option>' + (currentSupermarket || 'Seleziona') + '</option>');
            update();
        });

        $select.on('change', function(){
            currentSupermarket = $(this).val();
            page = 1;
            update();
        });
        $search.on('input', function(){
            currentQuery = $(this).val();
            page = 1;
            update();
        });
        $prev.on('click', function(){
            if (page > 1) { page--; update(); }
        });
        $next.on('click', function(){
            page++; update();
        });

        // Shopping list events
        const $cartList = $('#vg-shopping-list');
        const $cartTotal = $('#vg-shopping-total');
        const $cartClear = $('#vg-shopping-clear');
        const $cartCopy = $('#vg-shopping-copy');
        renderCart($cartList, $cartTotal);
        $cartList.on('click', '.vg-cart-dec', function(){ changeQty($(this).data('id'), -1); });
        $cartList.on('click', '.vg-cart-inc', function(){ changeQty($(this).data('id'), +1); });
        $cartList.on('click', '.vg-cart-remove', function(){ removeFromCart($(this).data('id')); });
        $cartClear.on('click', function(){ setCart([]); renderCart($cartList, $cartTotal); });
        $cartCopy.on('click', function(){
            const cart = getCart();
            const text = cart.map(i => `${i.qty} x ${i.nome}${i.prezzo? ' ('+i.prezzo+')' : ''}`).join('\n');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                const ta = document.createElement('textarea');
                ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch(e){}; document.body.removeChild(ta);
            }
        });
        // Compare prices
        const $compareBtn = $('#vg-shopping-compare');
        function renderCompare(results){
            const $res = $('#vg-compare-results');
            if (!$res.length) return;
            if (!results || !results.items || !results.items.length){ $res.html('<p>Nessun confronto disponibile.</p>'); return; }
            const html = results.items.map(row => {
                const q = row.query || {};
                const best = row.best;
                const offers = row.offers || [];
                let block = '<div class="vg-compare-item">';
                block += '<div class="vg-compare-query"><strong>'+ (q.nome||'') +'</strong>' + (q.marca? ' • '+q.marca : '') + ' × ' + (q.qty||1) + '</div>';
                if (best){
                    block += '<div class="vg-compare-best">Migliore: ' + (best.supermercato||'') + ' — ' + (best.prezzo||'') + '</div>';
                } else {
                    block += '<div class="vg-compare-best">Nessuna offerta trovata</div>';
                }
                if (offers.length){
                    block += '<ul class="vg-compare-offers">' + offers.slice(0,5).map(o => '<li>'+(o.supermercato||'')+': '+(o.prezzo||'')+'</li>').join('') + '</ul>';
                }
                block += '</div>';
                return block;
            }).join('');
            html += '<div class="vg-compare-total"><strong>Totale migliore stimato:</strong> € ' + (results.best_total!=null? results.best_total.toFixed(2) : '0.00') + '</div>';
            $res.html(html);
        }
        function doCompare(){
            const cart = getCart();
            const items = cart.map(i => ({ nome: i.nome, marca: i.marca, qty: i.qty }));
            if (!items.length){ renderCompare({ items: [] }); return; }
            $.ajax({
                url: window.VG_API_BASE + '/compare',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ items }),
                success: function(resp){ renderCompare(resp); },
                error: function(){ $('#vg-compare-results').html('<p>Errore nel confronto prezzi.</p>'); }
            });
        }
        if ($compareBtn.length){ $compareBtn.on('click', doCompare); }
    });
})(jQuery);