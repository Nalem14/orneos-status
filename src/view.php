<?php
declare(strict_types=1);

$services = $services ?? [];
$maintenanceNow = $maintenanceNow ?? false;
$notices = $notices ?? [];
$monitorEvents = $monitorEvents ?? [];
$locale = App\Core\Language::getLocale();
$en = $locale === 'en';
$tz = new DateTimeZone('Europe/Paris');
$noticeText = static fn (array $values): string => (string) (($values[$locale] ?? '') ?: ($values['fr'] ?? ''));
$activeNotices = array_values(array_filter($notices, static fn (array $n): bool => ($n['state'] ?? '') === 'active'));
$scheduledNotices = array_values(array_filter($notices, static fn (array $n): bool => ($n['state'] ?? '') === 'scheduled'));
usort($scheduledNotices, static fn (array $a, array $b): int => strcmp((string) ($a['scheduled_start'] ?? ''), (string) ($b['scheduled_start'] ?? '')));
$activeMonitorEvents = array_values(array_filter($monitorEvents, static fn (array $e): bool => empty($e['resolved_at'])));
$manualIncident = (bool) array_filter($activeNotices, static fn (array $n): bool => ($n['kind'] ?? '') === 'incident');
$manualMaintenance = !$manualIncident && (bool) array_filter($activeNotices, static fn (array $n): bool => ($n['kind'] ?? '') === 'maintenance');
$down = array_filter($services, static fn ($s) => $s['up'] === false);
$otherDown = array_filter($services, static fn ($s, string $key) => $key !== 'cloud' && $s['up'] === false, ARRAY_FILTER_USE_BOTH);
$overall = $manualIncident ? 'down' : ($manualMaintenance ? 'maintenance' : (!$fresh ? 'unknown' : ($otherDown || ($down && !$maintenanceNow) ? 'down' : ($maintenanceNow ? 'maintenance' : 'up'))));
$pct = static fn (?float $v): string => $v === null ? '—' : number_format(floor($v * 100) / 100, $v >= 99.995 ? 0 : 2, $en ? '.' : ',', '') . "\u{202F}%";
$level = static fn (?float $u): string => $u === null ? 'none' : ($u >= 99.5 ? 'up' : ($u >= 95 ? 'degraded' : 'down'));
$dayLabel = static fn (string $d): string => (new DateTimeImmutable($d))->format($en ? 'M j' : 'd/m');
$dateTime = static fn (string $d): string => (new DateTimeImmutable($d))->setTimezone(new DateTimeZone('Europe/Paris'))->format($en ? 'M j, Y H:i' : ($locale === 'de' ? 'd.m.Y, H:i' : 'd/m/Y à H:i'));
$duration = static function (string $start, ?string $end): string {
    $seconds = max(0, ($end ? strtotime($end) : time()) - strtotime($start));
    $minutes = max(1, (int) ceil($seconds / 60));
    return $minutes >= 60 ? floor($minutes / 60) . ' h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : $minutes . ' min';
};
$componentState = static function (string $key, array $service) use ($activeNotices, $maintenanceNow): string {
    $manual = array_values(array_filter($activeNotices, static fn (array $n): bool => in_array($key, (array) ($n['services'] ?? []), true)));
    if (array_filter($manual, static fn (array $n): bool => ($n['kind'] ?? '') === 'incident')) return 'down';
    if ($manual || ($key === 'cloud' && $maintenanceNow)) return 'maintenance';
    return $service['up'] === null ? 'unknown' : ($service['up'] ? 'up' : 'down');
};

$history = [];
foreach ($notices as $notice) {
    if (($notice['state'] ?? '') === 'active' || ($notice['state'] ?? '') === 'scheduled') continue;
    $history[] = ['source' => 'manual', 'at' => (string) ($notice['started_at'] ?? ''), 'end' => $notice['resolved_at'] ?? null, 'kind' => $notice['kind'] ?? 'incident', 'notice' => $notice];
}
foreach ($monitorEvents as $event) {
    if (empty($event['resolved_at'])) continue;
    $eventStart = strtotime((string) ($event['at'] ?? ''));
    $eventEnd = strtotime((string) ($event['resolved_at'] ?? $event['last_at'] ?? ''));
    $covered = false;
    foreach ($notices as $notice) {
        if (($notice['state'] ?? '') === 'active' || !in_array((string) ($event['service'] ?? ''), (array) ($notice['services'] ?? []), true)) continue;
        $noticeStart = strtotime((string) ($notice['started_at'] ?? ''));
        $noticeEnd = strtotime((string) ($notice['resolved_at'] ?? ''));
        if ($eventStart !== false && $eventEnd !== false && $noticeStart !== false && $noticeEnd !== false && $eventStart <= $noticeEnd + 600 && $noticeStart <= $eventEnd + 600) {
            $covered = true;
            break;
        }
    }
    if ($covered) continue;
    $history[] = ['source' => 'monitor', 'at' => (string) ($event['at'] ?? ''), 'end' => $event['resolved_at'] ?? $event['last_at'] ?? null, 'kind' => $event['kind'] ?? 'incident', 'event' => $event];
}
usort($history, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
$history = array_slice($history, 0, 30);
?>

<section class="status-hero" data-state="<?= $overall ?>">
    <div class="container">
        <div class="status-hero-state"><?= icon($overall === 'up' ? 'check' : 'alert') ?><div><span><?= translate('status_current_status') ?></span><h1><?= translate('status_overall_' . $overall) ?></h1><?php if ($checkedAt !== null): ?><p><?= translate('status_checked', ['date' => (new DateTimeImmutable('@' . $checkedAt))->setTimezone($tz)->format($en ? 'M j, H:i' : ($locale === 'de' ? 'd.m., H:i' : 'd/m à H:i'))]) ?></p><?php endif; ?></div></div>
        <a href="#history"><?= translate('status_view_history') ?><?= icon('arrow') ?></a>
    </div>
</section>

<div class="status-main container">
    <?php if ($activeNotices !== [] || $activeMonitorEvents !== []): ?>
    <section class="status-section status-active">
        <div class="status-section-head"><div><span class="status-kicker"><?= translate('status_active_incidents') ?></span><h2><?= translate('status_follow_live') ?></h2></div></div>
        <?php foreach ($activeNotices as $notice): $kind = ($notice['kind'] ?? '') === 'maintenance' ? 'maintenance' : 'incident'; ?>
            <article class="incident-card" data-kind="<?= $kind ?>">
                <header><div><span class="incident-dot"></span><span class="incident-state"><?= translate('status_incident_stage_' . (string) ($notice['stage'] ?? ($kind === 'maintenance' ? 'in_progress' : 'investigating'))) ?></span></div><time><?= $dateTime((string) ($notice['started_at'] ?? 'now')) ?></time></header>
                <h3><?= htmlspecialchars($noticeText((array) ($notice['titles'] ?? []))) ?></h3>
                <p class="incident-components"><?= translate('status_notice_services') ?> <?= htmlspecialchars(implode(' · ', array_map(static fn (string $s): string => translate('status_service_' . $s), (array) ($notice['services'] ?? [])))) ?></p>
                <ol class="incident-timeline"><?php foreach (array_reverse((array) ($notice['updates'] ?? [])) as $i => $update): $message = $noticeText((array) ($update['messages'] ?? [])); if ($message === '') continue; ?><li><div><b><?= $i === 0 ? translate($kind === 'maintenance' ? 'status_maintenance_in_progress' : 'status_incident_update') : translate('status_incident_update') ?></b><time><?= (new DateTimeImmutable((string) ($update['at'] ?? 'now')))->setTimezone($tz)->format('H:i') ?></time></div><p><?= nl2br(htmlspecialchars($message)) ?></p></li><?php endforeach; ?></ol>
            </article>
        <?php endforeach; ?>
        <?php foreach ($activeMonitorEvents as $event): $kind = ($event['kind'] ?? '') === 'maintenance' ? 'maintenance' : 'incident'; $serviceKey = (string) ($event['service'] ?? 'cloud'); ?>
            <article class="incident-card" data-kind="<?= $kind ?>">
                <header><div><span class="incident-dot"></span><span class="incident-state"><?= translate($kind === 'maintenance' ? 'status_state_maintenance' : 'status_incident_investigating') ?></span></div><time><?= $dateTime((string) ($event['at'] ?? 'now')) ?></time></header>
                <h3><?= translate($kind === 'maintenance' ? 'status_history_maintenance' : 'status_history_incident', ['service' => translate('status_service_' . $serviceKey)]) ?></h3>
                <p class="incident-components"><?= translate('status_automatic_detection') ?></p>
                <ol class="incident-timeline"><li><div><b><?= translate('status_incident_update') ?></b><time><?= (new DateTimeImmutable((string) ($event['last_at'] ?? $event['at'] ?? 'now')))->setTimezone($tz)->format('H:i') ?></time></div><p><?= translate($kind === 'maintenance' ? 'status_automatic_maintenance_text' : 'status_automatic_incident_text') ?></p></li></ol>
            </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <section class="status-section status-components">
        <div class="status-section-head"><div><span class="status-kicker"><?= translate('status_components') ?></span><h2><?= translate('status_services_title') ?></h2></div><div class="status-legend-inline"><span data-level="up"><?= translate('status_state_up') ?></span><span data-level="degraded"><?= translate('status_legend_degraded') ?></span><span data-level="down"><?= translate('status_legend_down') ?></span></div></div>
        <div class="component-list">
        <?php foreach ($services as $key => $service): $state = $componentState($key, $service); ?>
            <article class="component-row" data-state="<?= $state ?>">
                <div class="component-name"><span class="component-indicator"></span><div><h3><?= translate('status_service_' . $key) ?></h3><p><?= translate('status_service_' . $key . '_text') ?></p></div></div>
                <span class="component-state"><?= translate('status_state_' . $state) ?></span>
                <div class="component-uptime"><div class="uptime" role="img" aria-label="<?= htmlspecialchars(translate('status_uptime_label', ['pct' => $pct($service['uptime'])])) ?>"><?php foreach ($service['days'] as $d): ?><i data-level="<?= $level($d['uptime']) ?>"><span class="tip"><?= $dayLabel($d['day']) ?> · <?= $d['uptime'] === null ? translate('status_no_data') : $pct($d['uptime']) ?></span></i><?php endforeach; ?></div><div><span><?= translate('status_90_days') ?></span><b><?= $pct($service['uptime']) ?></b><span><?= translate('status_today') ?></span></div></div>
            </article>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="status-section scheduled-section">
        <div class="status-section-head"><div><span class="status-kicker"><?= translate('status_maintenance') ?></span><h2><?= translate('status_scheduled_maintenance') ?></h2></div></div>
        <?php if ($scheduledNotices === []): ?><div class="status-empty"><?= icon('calendar') ?><span><b><?= translate('status_no_scheduled_maintenance') ?></b><small><?= translate('status_no_scheduled_maintenance_text') ?></small></span></div><?php endif; ?>
        <?php foreach ($scheduledNotices as $notice): $updates = (array) ($notice['updates'] ?? []); $first = $updates[0] ?? []; ?>
            <article class="scheduled-card">
                <div class="scheduled-date"><span><?= (new DateTimeImmutable((string) ($notice['scheduled_start'] ?? $notice['started_at'])))->setTimezone($tz)->format('m') ?></span><b><?= (new DateTimeImmutable((string) ($notice['scheduled_start'] ?? $notice['started_at'])))->setTimezone($tz)->format('d') ?></b></div>
                <div class="scheduled-content"><span class="incident-state"><?= translate('status_incident_stage_scheduled') ?></span><h3><?= htmlspecialchars($noticeText((array) ($notice['titles'] ?? []))) ?></h3><p><?= nl2br(htmlspecialchars($noticeText((array) ($first['messages'] ?? [])))) ?></p><small><?= translate('status_scheduled_window', ['start' => $dateTime((string) ($notice['scheduled_start'] ?? $notice['started_at'])), 'end' => $dateTime((string) ($notice['scheduled_end'] ?? $notice['started_at']))]) ?> · <?= $duration((string) ($notice['scheduled_start'] ?? $notice['started_at']), (string) ($notice['scheduled_end'] ?? $notice['started_at'])) ?></small><small><?= translate('status_notice_services') ?> <?= htmlspecialchars(implode(' · ', array_map(static fn (string $s): string => translate('status_service_' . $s), (array) ($notice['services'] ?? [])))) ?></small></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="status-section" id="history">
        <div class="status-section-head"><div><span class="status-kicker"><?= translate('status_last_90_days') ?></span><h2><?= translate('status_incident_history') ?></h2></div></div>
        <?php if ($history === []): ?><div class="status-empty"><?= icon('check') ?><span><b><?= translate('status_no_recent_incident') ?></b><small><?= translate('status_no_recent_incident_text') ?></small></span></div><?php else: ?><div class="incident-history">
        <?php foreach ($history as $item):
            $isManual = $item['source'] === 'manual'; $notice = $item['notice'] ?? []; $event = $item['event'] ?? [];
            $kind = $item['kind'] === 'maintenance' ? 'maintenance' : 'incident';
            $serviceKey = (string) ($event['service'] ?? 'cloud');
            $title = $isManual ? $noticeText((array) ($notice['titles'] ?? [])) : translate($kind === 'maintenance' ? 'status_history_maintenance' : 'status_history_incident', ['service' => translate('status_service_' . $serviceKey)]);
        ?>
            <?php $cancelled = $isManual && ($notice['state'] ?? '') === 'cancelled'; ?>
            <details class="history-item" data-kind="<?= $kind ?>">
                <summary><span class="history-mark"></span><span class="history-title"><b><?= htmlspecialchars($title) ?></b><small><?= $dateTime($item['at']) ?><?= $cancelled ? '' : ' · ' . $duration($item['at'], $item['end']) ?></small></span><span class="history-status"><?= translate($cancelled ? 'status_maintenance_cancelled' : ($kind === 'maintenance' ? 'status_maintenance_completed' : 'status_notice_resolved')) ?></span><span class="chev"></span></summary>
                <div class="history-body"><p class="incident-components"><?= translate('status_notice_services') ?> <?= $isManual ? htmlspecialchars(implode(' · ', array_map(static fn (string $s): string => translate('status_service_' . $s), (array) ($notice['services'] ?? [])))) : translate('status_service_' . $serviceKey) ?></p>
                <?php if ($isManual): ?><ol class="incident-timeline"><?php foreach (array_reverse((array) ($notice['updates'] ?? [])) as $update): $message = $noticeText((array) ($update['messages'] ?? [])); if ($message === '') continue; ?><li><div><b><?= translate('status_incident_update') ?></b><time><?= $dateTime((string) ($update['at'] ?? 'now')) ?></time></div><p><?= nl2br(htmlspecialchars($message)) ?></p></li><?php endforeach; ?></ol><?php else: ?><p><?= translate($kind === 'maintenance' ? 'status_history_maintenance_text' : 'status_history_incident_text') ?></p><?php endif; ?></div>
            </details>
        <?php endforeach; ?></div><?php endif; ?>
    </section>

    <footer class="status-page-foot"><p><?= translate('status_footer', ['link' => '<a href="mailto:contact@orneos.eu">contact@orneos.eu</a>']) ?></p><span><?= translate('status_monitor_note') ?></span></footer>
</div>
