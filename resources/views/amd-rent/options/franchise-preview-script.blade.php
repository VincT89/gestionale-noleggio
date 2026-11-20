@push('scripts')
    <script>
        (() => {
            const vehicles = @js($previewVehicles);
            const scale = BigInt(@js($multiplierScale));
            const limit = BigInt(@js(\App\Services\AmdRent\BookingOptions::MAX_MONEY_CENTS));
            const currency = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', useGrouping: 'always' });
            const scope = document.getElementById('option-scope');
            const coverage = document.getElementById('option-coverage');
            const type = document.getElementById('option-type');
            const picker = document.getElementById('option-preview-vehicle');
            const pickerField = document.getElementById('option-preview-picker');
            const empty = document.getElementById('option-preview-status');
            const rows = Array.from(coverage.querySelectorAll('[data-franchise-preview]'), row => ({
                key: row.dataset.franchisePreview,
                included: row.querySelector('input[type="checkbox"]'),
                fixed: row.querySelector('[data-preview-fixed]'),
                factor: row.querySelector('[data-preview-factor]'),
                amounts: row.querySelector('[data-preview-amounts]'),
                base: row.querySelector('[data-preview-base]'),
                result: row.querySelector('[data-preview-result]'),
            }));

            function formatCents(cents) {
                return cents > limit ? 'Oltre il limite gestibile' : currency.format(Number(cents) / 100);
            }

            function validDecimal(input, precision) {
                return input.validity.valid && /^[+-]?\d*\.?\d*$/.test(input.value)
                    && (input.value.split('.')[1]?.length ?? 0) <= precision;
            }

            function estimate({ included, fixed, factor }, base) {
                if (type?.value === 'policy') return 'Polizza separata';
                if (included && !included.checked) return type ? 'Franchigia invariata' : 'Copertura esclusa';
                if (!validDecimal(fixed, 2) || !validDecimal(factor, 4)) return 'Controlla gli importi';
                if (fixed.value && factor.value) return 'Scegli un importo o un moltiplicatore';
                if (fixed.value) return formatCents(BigInt(Math.round(fixed.valueAsNumber * 100)));
                if (type && !factor.value) return 'Indica la franchigia AMD Plus';
                if (base === null) return 'Importo base mancante';
                if (base > limit) return formatCents(base);

                /** Arrotonda al centesimo nello stesso modo del checkout. */
                const multiplier = factor.value ? BigInt(Math.round(factor.valueAsNumber * Number(scale))) : scale;
                return formatCents((base * multiplier + scale / 2n) / scale);
            }

            function refreshAmounts() {
                const vehicle = vehicles.find(item => item.id === picker.value);
                for (const row of rows) {
                    row.amounts.hidden = !vehicle;
                    if (!vehicle) continue;

                    const raw = vehicle.franchises[row.key];
                    const base = raw === null ? null : BigInt(raw);
                    row.base.textContent = base === null ? 'Da impostare nella scheda' : formatCents(base);
                    const result = estimate(row, base);
                    if (row.result.textContent !== result) row.result.textContent = result;
                }
            }

            function refreshVehicles() {
                const target = scope.value;
                const single = target.startsWith('vehicle:');
                const available = vehicles.filter(vehicle =>
                    target === 'all' || vehicle.group === target || `vehicle:${vehicle.id}` === target);
                const vehicle = single ? available[0] : available.find(item => item.id === picker.value);
                picker.replaceChildren(new Option('Scegli un’auto', ''), ...available.map(item => new Option(item.label, item.id)));
                picker.value = vehicle?.id ?? '';
                pickerField.hidden = single || available.length === 0;
                empty.hidden = available.length !== 0;
                refreshAmounts();
            }

            scope.addEventListener('change', refreshVehicles);
            coverage.addEventListener('input', refreshAmounts);
            type?.addEventListener('change', refreshAmounts);
            refreshVehicles();
        })();
    </script>
@endpush
