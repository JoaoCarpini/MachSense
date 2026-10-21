<?php

date_default_timezone_set('America/Sao_Paulo');

// O ícone é o conteúdo interno de um <svg> 24x24 com traço
$itensMenu = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php',
        'icone' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
    'maquinas' => ['label' => 'Máquinas', 'href' => 'maquinas.php',
        'icone' => '<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>'],
    'paradas' => ['label' => 'Paradas', 'href' => 'paradas.php',
        'icone' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>'],
    'relatorios' => ['label' => 'Relatórios', 'href' => 'relatorios.php',
        'icone' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 17v-3M12 17v-6M16 17v-2"/>'],
    'configuracoes' => ['label' => 'Configurações', 'href' => 'configuracoes.php',
        'icone' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'],
];

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MachSense — <?= e($tituloPagina ?? 'Dashboard') ?></title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<div class="app">

    <!-- ============ MENU LATERAL ============ -->
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v18h18"/><path d="M18.7 8 12 14.7 8.5 11.2 3 16.7"/>
                </svg>
            </span>
            <div>
                <strong>MachSense</strong>
                <small>PRODUÇÃO INDUSTRIAL</small>
            </div>
        </div>

        <nav class="nav" aria-label="Navegação principal">
            <?php foreach ($itensMenu as $chave => $item): ?>
                <a href="<?= e($item['href']) ?>"
                   class="nav-link<?= ($paginaAtiva ?? '') === $chave ? ' is-active' : '' ?>"
                   <?= ($paginaAtiva ?? '') === $chave ? 'aria-current="page"' : '' ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $item['icone'] ?></svg>
                    <?= e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <p class="line-status"><span class="dot"></span>LINHA_PROD #01 : OK</p>
            <div class="user">
                <span class="avatar" aria-hidden="true"><?= e($iniciaisUsuario) ?></span>
                <div>
                    <strong><?= e($nomeUsuario) ?></strong>
                    <?php if (!empty($cargoUsuario)): ?>
                        <small><?= e($cargoUsuario) ?></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </aside>

    <!-- ============ ÁREA PRINCIPAL ============ -->
    <main class="main">

        <header class="topbar">
            <div>
                <h1><?= e($tituloPagina ?? '') ?></h1>
                <p><?= e($subtituloPagina ?? '') ?></p>
            </div>
            <div class="topbar-actions">
                <span class="plant-time">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    PLANT_TIME: <span id="plant-time"><?= date('H:i:s') ?></span>
                </span>
                <a href="logout.php" class="btn-logout" title="Sair do sistema" aria-label="Sair do sistema">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                </a>
            </div>
        </header>
