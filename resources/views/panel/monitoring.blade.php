@extends('panel.layout')

@section('title', 'Monitoring')
@section('section-label', 'Modul · N° 006')
@section('breadcrumb', 'Monitoring')

@section('content')
<div class="space-y-6">
    <div class="card-editorial p-6 animate-fade-up">
        <div class="font-mono text-[10px] tracking-[0.22em] uppercase text-paper-dim mb-2">Sistem</div>
        <h1 class="title-editorial text-paper">Pemantauan VPS</h1>
        <p class="text-paper-soft mt-3 max-w-2xl">
            Tampilan ringkas sumber daya host: CPU, memori, disk, jaringan, layanan, port. Streaming
            via Reverb pada channel <code class="font-mono text-copper">monitoring.host</code>.
        </p>
        <p class="text-paper-dim text-sm mt-3 italic">
            UI penuh (uPlot charts, dashboard strip, alert log) menyusul di story v3.2-08.
        </p>
    </div>
</div>
@endsection
