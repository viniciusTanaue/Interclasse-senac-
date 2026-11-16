/**
 * Interclasse 2026 - Senac Bauru
 * Script JavaScript para UI, Modais, Filtros e Accordions
 */

document.addEventListener('DOMContentLoaded', () => {

  // --- 1. ACCORDION (Regulamento) ---
  const accordionHeaders = document.querySelectorAll('.accordion-header');
  accordionHeaders.forEach(header => {
    header.addEventListener('click', () => {
      const parent = header.closest('.accordion-item');
      if (parent) {
        parent.classList.toggle('open');
      }
    });
  });

  // --- 2. FILTRO DE PARTIDAS (Jogos do Dia) ---
  const matchChips = document.querySelectorAll('.chip[data-filter]');
  const matchCards = document.querySelectorAll('.match-card');

  if (matchChips.length > 0 && matchCards.length > 0) {
    matchChips.forEach(chip => {
      chip.addEventListener('click', () => {
        matchChips.forEach(c => c.classList.remove('active'));
        chip.classList.add('active');

        const filter = chip.getAttribute('data-filter');

        matchCards.forEach(card => {
          const cardStatus = card.getAttribute('data-status');
          const cardModality = card.getAttribute('data-modality');

          if (filter === 'todos') {
            card.style.display = 'block';
          } else if (filter === 'finalizado' || filter === 'agendado') {
            card.style.display = (cardStatus === filter) ? 'block' : 'none';
          } else if (cardModality && cardModality.toLowerCase().includes(filter.toLowerCase())) {
            card.style.display = 'block';
          } else {
            card.style.display = 'none';
          }
        });
      });
    });
  }

  // --- 3. ABAS DE DENÚNCIAS ---
  const tabBtns = document.querySelectorAll('.denuncias-tab-btn');
  const tabPanels = document.querySelectorAll('.denuncias-panel');

  if (tabBtns.length > 0 && tabPanels.length > 0) {
    tabBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        tabBtns.forEach(b => b.classList.remove('active'));
        tabPanels.forEach(p => p.style.display = 'none');

        btn.classList.add('active');
        const targetId = btn.getAttribute('data-target');
        const targetPanel = document.getElementById(targetId);
        if (targetPanel) {
          targetPanel.style.display = 'block';
        }
      });
    });
  }

  // --- 3b. CAMPO DE PONTOS NA MODERAÇÃO DE DENÚNCIAS ---
  // Mostra o campo de pontos apenas quando a decisão é "aceita"
  function togglePontosField(selectEl) {
    const denId = selectEl.getAttribute('data-den-id');
    const pontosGroup = document.getElementById('pontos-group-' + denId);
    const pontosInput = document.getElementById('pontos-input-' + denId);
    const pontosHidden = document.getElementById('pontos-hidden-' + denId);

    if (!pontosGroup) return;

    if (selectEl.value === 'aceita') {
      pontosGroup.style.display = 'block';
      if (pontosInput) pontosInput.disabled = false;
      if (pontosHidden) pontosHidden.disabled = true;
    } else {
      pontosGroup.style.display = 'none';
      if (pontosInput) pontosInput.disabled = true;
      if (pontosHidden) { pontosHidden.disabled = false; pontosHidden.value = '0'; }
    }
  }

  // Inicializa e escuta mudanças em todos os selects de julgamento
  const statusSelects = document.querySelectorAll('.julgamento-status-select');
  statusSelects.forEach(sel => {
    togglePontosField(sel); // Estado inicial
    sel.addEventListener('change', () => togglePontosField(sel));
  });

  // --- 4. MODAL GENÉRICO E DETALHES DA EQUIPE ---
  const modalOverlay = document.getElementById('teamModalOverlay');
  const modalCloseBtn = document.getElementById('modalCloseBtn');

  if (modalCloseBtn && modalOverlay) {
    modalCloseBtn.addEventListener('click', () => {
      closeTeamModal();
    });

    modalOverlay.addEventListener('click', (e) => {
      if (e.target === modalOverlay) {
        closeTeamModal();
      }
    });
  }

  // Atalho para fechar com tecla ESC
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeTeamModal();
    }
  });

});

// Função para abrir modal de equipe com dados dinâmicos
function openTeamModal(teamData) {
  const overlay = document.getElementById('teamModalOverlay');
  if (!overlay) return;

  const logoEl = document.getElementById('modalTeamLogo');
  if (logoEl) logoEl.src = teamData.logo || 'assets/teams/athena.svg';

  const nameEl = document.getElementById('modalTeamName');
  if (nameEl) nameEl.textContent = teamData.nome || 'Equipe';

  const pointsEl = document.getElementById('modalTeamPoints');
  if (pointsEl) pointsEl.textContent = teamData.pontos ?? '0';

  const gamesEl = document.getElementById('modalTeamGames');
  if (gamesEl) gamesEl.textContent = teamData.jogos ?? '0';

  const winsEl = document.getElementById('modalTeamWins');
  if (winsEl) winsEl.textContent = teamData.vitorias ?? '0';

  const drawsEl = document.getElementById('modalTeamDraws');
  if (drawsEl) drawsEl.textContent = teamData.empates ?? '0';

  const lossesEl = document.getElementById('modalTeamLosses');
  if (lossesEl) lossesEl.textContent = teamData.derrotas ?? '0';

  // Renderiza a lista de modalidades e pontos acumulados
  const modalitiesList = document.getElementById('modalTeamModalitiesList');
  if (modalitiesList) {
    modalitiesList.innerHTML = '';
    const mods = teamData.modalidades_pontos || {};
    const modKeys = Object.keys(mods);

    if (modKeys.length === 0) {
      const emptyMessage = document.createElement('div');
      emptyMessage.style.cssText = 'font-size:0.75rem; color:#78909C; padding:6px; background:#F5F6F8; border-radius:6px;';
      emptyMessage.textContent = 'Nenhum ponto registrado em modalidades até o momento.';
      modalitiesList.appendChild(emptyMessage);
    } else {
      modKeys.forEach(key => {
        const pts = mods[key];
        const isPositive = pts >= 0;
        const itemRow = document.createElement('div');
        itemRow.style.cssText = 'display:flex; justify-content:space-between; align-items:center; background:#F5F6F8; padding:8px 10px; border-radius:6px; font-size:0.8rem;';
        const modalityName = document.createElement('strong');
        modalityName.textContent = key;
        const points = document.createElement('span');
        points.style.cssText = `font-weight:800; color:${isPositive ? 'var(--success)' : 'var(--danger)'};`;
        points.textContent = `${isPositive ? '+' : ''}${pts} pts`;
        itemRow.append(modalityName, points);
        modalitiesList.appendChild(itemRow);
      });
    }
  }

  overlay.classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeTeamModal() {
  const overlay = document.getElementById('teamModalOverlay');
  if (overlay) {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }
}

// Função para download amigável do regulamento oficial (PDF / DOCX)
function downloadRegulamento(tipo) {
  const link = document.createElement('a');
  const filename = `Regulamento_Interclasse_Senac_Bauru_2026.${tipo}`;
  
  // Cria um arquivo de texto com os detalhes do regulamento
  const textContent = `SENAC BAURU - REGULAMENTO GERAL DO INTERCLASSE 2026\n\n` +
    `1. INSCRIÇÕES: Participação exclusiva de alunos devidamente matriculados.\n` +
    `2. UNIFORMES: Camisa oficial da equipe ou colete fornecido pela organização.\n` +
    `3. PONTUAÇÃO: Vitória = 3 pontos | Empate = 1 ponto | Derrota = 0 pontos.\n` +
    `4. PENALIDADES: Cartão Amarelo (-1 pt), Cartão Vermelho (-3 pts), Desrespeito (-10 pts).\n` +
    `5. FAIR PLAY: O respeito entre alunos, professores e arbitragem é inegociável.\n\n` +
    `Documento emitido digitalmente pela Coordenação Esportiva Senac Bauru.`;

  const blob = new Blob([textContent], { type: tipo === 'pdf' ? 'application/pdf' : 'application/msword' });
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
