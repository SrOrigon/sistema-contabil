function nomeDosArquivos(campo) {
  const quantidade = campo.files ? campo.files.length : 0;
  if (quantidade === 0) {
    return 'Nenhum arquivo escolhido';
  }
  if (quantidade === 1) {
    return campo.files[0].name;
  }
  return quantidade + ' arquivos escolhidos';
}

document.querySelectorAll('input[type="file"]').forEach((campo) => {
  const linha = document.createElement('div');
  linha.className = 'acoes';
  const botao = document.createElement('button');
  botao.type = 'button';
  botao.className = 'secundario';
  botao.textContent = campo.multiple ? 'Escolher arquivos' : 'Escolher arquivo';
  const nome = document.createElement('span');
  nome.className = 'sub';
  nome.textContent = nomeDosArquivos(campo);
  campo.classList.add('arquivo-oculto');
  campo.after(linha);
  linha.append(botao, nome);
  botao.addEventListener('click', () => campo.click());
  campo.addEventListener('change', () => {
    nome.textContent = nomeDosArquivos(campo);
  });
});

document.querySelectorAll('[data-drop]').forEach((zona) => {
  const campo = zona.querySelector('input[type="file"]');
  ['dragover', 'dragenter'].forEach((evento) => zona.addEventListener(evento, (e) => {
    e.preventDefault();
    zona.classList.add('sobre');
  }));
  zona.addEventListener('dragleave', () => zona.classList.remove('sobre'));
  zona.addEventListener('drop', (e) => {
    e.preventDefault();
    zona.classList.remove('sobre');
    if (campo && e.dataTransfer) {
      campo.files = e.dataTransfer.files;
      campo.dispatchEvent(new Event('change'));
    }
  });
});

const seletorEmpresa = document.querySelector('[data-empresa-ativa]');
if (seletorEmpresa && seletorEmpresa.form) {
  seletorEmpresa.addEventListener('change', () => seletorEmpresa.form.requestSubmit());
}

const menu = document.querySelector('[data-menu]');
const barra = document.querySelector('.sidebar');
if (menu && barra) {
  menu.addEventListener('click', () => {
    const aberto = barra.classList.toggle('aberta');
    menu.setAttribute('aria-expanded', aberto ? 'true' : 'false');
    menu.textContent = aberto ? 'Ocultar menu' : 'Mostrar menu';
  });
}

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
