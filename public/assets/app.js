document.querySelectorAll('[data-drop]').forEach((zona) => {
  const campo = zona.querySelector('input[type="file"]');
  ['dragover', 'dragenter'].forEach((evento) => zona.addEventListener(evento, (e) => {
    e.preventDefault();
    zona.style.borderColor = '#c4622d';
  }));
  zona.addEventListener('dragleave', () => { zona.style.borderColor = ''; });
  zona.addEventListener('drop', (e) => {
    e.preventDefault();
    zona.style.borderColor = '';
    if (campo && e.dataTransfer) {
      campo.files = e.dataTransfer.files;
    }
  });
});

document.querySelectorAll('[data-adicionar-partida]').forEach((botao) => {
  botao.addEventListener('click', () => {
    const modelo = document.querySelector('#modelo-partida');
    const destino = document.querySelector('#partidas');
    if (modelo && destino) {
      destino.appendChild(modelo.content.cloneNode(true));
    }
  });
});

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').catch(() => {});
}
