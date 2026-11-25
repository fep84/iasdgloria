/*
 * comum/carregar.js
 * Monta as páginas de uma classe usando os arquivos comuns desta pasta.
 * Cada classe (ex.: adultos/vivendo-o-reino/) tem só páginas pequenas que chamam este arquivo:
 *   <script src="../../comum/carregar.js" data-pagina="publica" data-classe="Vivendo o Reino"></script>
 * As páginas comuns ficam em comum/publica.html, comum/editar.html e comum/admin.html.
 */
(function () {
    const s = document.currentScript;
    const pagina = s.dataset.pagina;
    const nome = s.dataset.classe || '';
    // Endereço .../classes-escola-sabatina/<tipo>/<classe>/ (ex.: adultos/vivendo-o-reino)
    const partes = location.pathname.replace(/[^/]*$/, '').split('/').filter(Boolean);
    const pasta = partes[partes.length - 1] || '', tipo = partes[partes.length - 2] || '';
    const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Usado pelas páginas comuns
    window.ES = {
        nome, pasta, tipo,
        api: (arquivo, extra = '') => `../../api/${arquivo}?tipo=${encodeURIComponent(tipo)}&classe=${encodeURIComponent(pasta)}${extra ? '&' + extra : ''}`,
        // Arquivos do tipo de lição (ilustrações das lições), que ficam na pasta de cima
        comum: caminho => (!caminho || /^(https?:|data:|\.\.\/)/.test(caminho)) ? caminho : '../' + caminho
    };

    fetch(new URL(pagina + '.html', s.src).href, { cache: 'no-cache' })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(html => {
            const doc = new DOMParser().parseFromString(html.split('{{CLASSE}}').join(esc(nome)), 'text/html');
            doc.head.querySelectorAll('style, link[rel="stylesheet"]').forEach(e => document.head.appendChild(document.importNode(e, true)));
            document.body.innerHTML = doc.body.innerHTML;
            // Scripts colocados via innerHTML não rodam: recria cada um, na ordem
            document.body.querySelectorAll('script').forEach(velho => {
                const novo = document.createElement('script');
                [...velho.attributes].forEach(a => novo.setAttribute(a.name, a.value));
                novo.textContent = velho.textContent;
                velho.replaceWith(novo);
            });
        })
        .catch(e => {
            console.error(e);
            document.body.innerHTML = '<p style="font-family:sans-serif;padding:24px">Não foi possível carregar a página. Atualize para tentar de novo.</p>';
        });
})();
