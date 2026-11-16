<div class="cert" style="border:2px solid #0d3b73;padding:28px 32px;font-family:'DejaVu Sans',Arial,sans-serif;color:#0f2742;font-size:12px;background:#fff">
    <table style="width:100%;border-collapse:collapse"><tr>
        <td style="width:50%;vertical-align:top"><div style="font-size:20px;font-weight:bold;color:#0a2a52">KBT RUVIA <span style="color:#0c9a92">BioTech</span></div>
            <div style="color:#61748c;font-size:10px">Biomedical Maintenance &amp; Installations Management System</div></td>
        <td style="width:50%;vertical-align:top;text-align:right"><div style="font-size:15px;font-weight:bold">{{ $facility->name }}</div>
            <div style="color:#61748c;font-size:10px">{{ collect([$facility->city, $facility->state])->filter()->implode(', ') }}</div></td>
    </tr></table>

    <div style="text-align:center;margin:24px 0 6px;font-size:18px;font-weight:bold;letter-spacing:.06em">COMMISSIONING CERTIFICATE</div>
    <div style="text-align:center;color:#61748c;font-size:10px;margin-bottom:18px">Certificate No. {{ $job->certificate_number }} · Job {{ $job->job_number }}</div>

    <table style="width:100%;border-collapse:collapse;font-size:12px">
        @foreach([
            ['Asset ID', $asset->asset_tag, 'Serial Number', $asset->serial_number],
            ['Equipment', $asset->name, 'Department', $job->department?->name ?? '—'],
            ['Manufacturer', $manufacturer ?: '—', 'Installation Date', $installedOn],
            ['Model', $asset->model ?: '—', 'Commissioning Date', $commissionedOn],
        ] as [$k1, $v1, $k2, $v2])
            <tr>
                <td style="padding:6px 8px;color:#61748c;width:18%">{{ $k1 }}</td><td style="padding:6px 8px;width:32%;font-weight:bold">{{ $v1 }}</td>
                <td style="padding:6px 8px;color:#61748c;width:20%">{{ $k2 }}</td><td style="padding:6px 8px;width:30%;font-weight:bold">{{ $v2 }}</td>
            </tr>
        @endforeach
    </table>

    <p style="margin:20px 0 28px;line-height:1.6">This is to certify that the above equipment has been installed, tested and commissioned as per the manufacturer's specifications. All site readiness, installation and acceptance checks were completed and the equipment is fit for clinical use.</p>

    <table style="width:100%;border-collapse:collapse;margin-top:30px"><tr>
        <td style="width:50%;text-align:center;vertical-align:bottom;padding:0 16px">
            <div style="border-top:1px solid #0f2742;padding-top:6px"><strong>{{ $engineer?->name ?? '—' }}</strong><br><span style="color:#61748c">{{ $engineer?->designation ?: 'Responsible Engineer' }}</span></div>
        </td>
        <td style="width:50%;text-align:center;vertical-align:bottom;padding:0 16px">
            @if(! $pdf && $handover)<img src="{{ route('installations.signature', $job) }}" alt="Department head signature" style="height:48px;display:block;margin:0 auto 4px">@endif
            <div style="border-top:1px solid #0f2742;padding-top:6px"><strong>{{ $head?->name ?? '—' }}</strong><br><span style="color:#61748c">{{ $head?->designation ?: 'Department Head' }}{{ $handover ? '' : ' (handover pending)' }}</span></div>
        </td>
    </tr></table>
</div>
