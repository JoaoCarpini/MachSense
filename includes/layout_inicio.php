<?php

date_default_timezone_set('America/Sao_Paulo');

$itensMenu = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    'maquinas' => ['label' => 'Máquinas', 'href' => 'maquinas.php'],
    'paradas' => ['label' => 'Paradas', 'href' => 'paradas.php'],
    'relatorios' => ['label' => 'Relatórios', 'href' => 'relatorios.php'],
    'configuracoes' => ['label' => 'Configurações', 'href' => 'configuracoes.php'],
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
                   <?= ($paginaAtiva ?? '') === $chave ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
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
