{{-- resources/views/pages/rentals/partials/actions.blade.php --}}
{{-- =========================
     CARGOS (Livewire zone)
     IMPORTANT: deve stare FUORI da wire:ignore
   ========================= --}}
@php
    $lastCheck = $this->cargosLastCheck;
    $lastSend  = $this->cargosLastSend;

    $cargosEnabled = !in_array($rental->status, ['closed','cancelled','canceled','no_show'], true);

    $stageLabel = [
        'resolver'        => 'Preparazione dati',
        'builder'         => 'Costruzione contratto',
        'formatter'       => 'Formattazione record',
        'api.token'       => 'Autenticazione',
        'api.check'       => 'Verifica CARGOS',
        'preflight.send'  => 'Pre-controllo invio',
        'api.send'        => 'Invio CARGOS',
    ];

    $fmtStage = fn($s) => $stageLabel[$s] ?? (is_string($s) ? str_replace(['api.','preflight.'], ['API ','Preflight '], $s) : '—');

    $badgeOkKo = function ($tx, string $prefixOk, string $prefixKo) {
        if (!$tx) return null;
        return [
            'class' => $tx->ok ? 'badge-success' : 'badge-error',
            'text'  => ($tx->ok ? $prefixOk : $prefixKo) . ': ' . ($tx->ok ? 'OK' : 'KO'),
        ];
    };

    $checkBadge = $badgeOkKo($lastCheck, 'Verifica', 'Verifica');
    $sendIsDry  = (bool) data_get($lastSend?->api_response, '0.dry_run', false) || ($lastSend?->stage === 'preflight.send');
    $sendBadge  = $badgeOkKo($lastSend, $sendIsDry ? 'Invio (simul.)' : 'Invio', 'Invio');

@endphp

<div class="rounded-lg border border-base-300 bg-base-100 p-3 space-y-3">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="font-semibold text-sm">CARGOS</div>
            <div class="text-xs opacity-70">
              @if(!$cargosEnabled)
                  Non disponibile su noleggi <span class="font-medium">chiusi</span> o <span class="font-medium">annullati</span>.
              @endif
            </div>
        </div>

        <div class="flex flex-col items-end gap-1">
            @if($checkBadge)
                <span class="badge {{ $checkBadge['class'] }} badge-sm">{{ $checkBadge['text'] }}</span>
            @endif
            @if($sendBadge)
                <span class="badge {{ $sendBadge['class'] }} badge-sm">{{ $sendBadge['text'] }}</span>
            @endif
        </div>
    </div>

    <div class="text-xs opacity-80 space-y-1">
        @if($lastCheck)
            <div class="flex justify-between gap-2">
                <span>Ultima verifica:</span>
                <span class="font-medium">
                    {{ optional($lastCheck->created_at)->format('d/m/Y H:i') }}
                    · {{ $fmtStage($lastCheck->stage) }}
                </span>
            </div>
        @endif

        @if($lastSend)
            <div class="flex justify-between gap-2">
                <span>Ultimo invio:</span>
                <span class="font-medium">
                    {{ optional($lastSend->created_at)->format('d/m/Y H:i') }}
                    · {{ $fmtStage($lastSend->stage) }}
                </span>
            </div>
        @endif

        @if(!$lastCheck && !$lastSend)
            <div class="opacity-70">Nessuna trasmissione registrata.</div>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-2">
        <button
            type="button"
            class="btn btn-sm w-full shadow-none
                  !bg-info !text-info-content !border-info
                  hover:brightness-95 focus-visible:outline-none focus-visible:ring focus-visible:ring-info/30
                  disabled:opacity-40 disabled:cursor-not-allowed"
            wire:click="cargosCheck"
            wire:loading.attr="disabled"
            wire:target="cargosCheck"
            @disabled(!$cargosEnabled)
        >
            <span wire:loading.remove wire:target="cargosCheck">Verifica</span>
            <span wire:loading wire:target="cargosCheck" class="loading loading-spinner loading-xs"></span>
        </button>

        <button
            type="button"
            class="btn btn-sm w-full shadow-none
                  !bg-success !text-success-content !border-success
                  hover:brightness-95 focus-visible:outline-none focus-visible:ring focus-visible:ring-success/30
                  disabled:opacity-40 disabled:cursor-not-allowed"
            wire:click="cargosSend"
            wire:loading.attr="disabled"
            wire:target="cargosSend"
            @disabled(!$cargosEnabled)
        >
            <span wire:loading.remove wire:target="cargosSend">Invia</span>
            <span wire:loading wire:target="cargosSend" class="loading loading-spinner loading-xs"></span>
        </button>

    </div>
</div>

@include('pages.rentals.partials.payment-history', ['payments' => $this->recordedPayments])

{{-- Azioni sul noleggio + Modale registrazione pagamento --}}
<div
    wire:ignore
    x-data="rentalActions({
        phase: @js($rental->status),
        flags: {
            hasBasePayment:      @js($rental->has_base_payment),
            hasOveragePayment:   @js($rental->has_distance_overage_payment),
        },
        // ✅ Totale acconti già PAGATI (iniziale)
        accontoPaid: {{ (float) $rental->charges()->where('kind','acconto')->where('payment_recorded', true)->sum('amount') }},

        overageUrl: '{{ route('rentals.distance_overage', $rental) }}'
    })"
    x-init="init()"
    class="space-y-3"
>
    {{-- BADGE acconto registrato (live) --}}
    <template x-if="Number(accontoPaid) > 0">
        <div class="flex items-center justify-between rounded-md border border-info/30 bg-info/10 px-3 py-2">
            <div class="text-sm">
                <span class="font-medium">Acconto:</span>
            </div>

            <span class="badge badge-info font-semibold text-sm" x-text="formatEuro(accontoPaid)"></span>
        </div>
    </template>

    {{-- BADGE pagamento base mancante (serve per chiudere) --}}
    <template x-if="phase !== 'close' && !flags.hasBasePayment">
        <div class="flex items-center justify-between rounded-md border border-warning/30 bg-warning/10 px-3 py-2">
            <div class="text-sm">
                <span class="font-medium">Pagamento base:</span>
            </div>

            <span class="badge badge-warning font-semibold text-sm">Mancante</span>
        </div>
    </template>

    {{-- BADGE chilometri extra da pagare (deriva da overage.amount, non da flag "needs") --}}
    <template x-if="overage.ready && Number(overage.amount) > 0 && !flags.hasOveragePayment">
        <div class="flex items-center justify-between rounded-md border border-warning/30 bg-warning/10 px-3 py-2">
            <div class="text-sm">
                <span class="font-medium">Km extra:</span>
                <span class="opacity-80">devi registrare il pagamento per chiudere</span>
            </div>

            {{-- ✅ ora mostra importo + km extra (se disponibili) --}}
            <span class="badge badge-warning font-semibold text-sm h-auto" x-text="formatOverageBadge()"></span>
        </div>
    </template>

    {{-- Registra Pagamento (sempre attivo) --}}
    <button
        class="btn btn-secondary btn-block shadow-none
               !bg-secondary !text-secondary-content !border-secondary
               hover:brightness-95 focus-visible:outline-none focus-visible:ring focus-visible:ring-secondary/30
               disabled:opacity-50 disabled:cursor-not-allowed"
        :disabled="loading"
        @click="$dispatch('open-payment-modal')"
    >
        <span x-show="!loading">Registra Pagamento</span>
        <span x-show="loading" class="loading loading-spinner loading-sm"></span>
    </button>

    {{-- Passa a In-use --}}
    <form method="POST" action="{{ route('rentals.inuse', $rental) }}" data-phase="in_use" x-on:submit.prevent="submit($el)">
        @csrf
        <button class="btn btn-info btn-block shadow-none
                      !bg-info !text-info-content !border-info
                      hover:brightness-95 focus-visible:outline-none focus-visible:ring focus-visible:ring-info/30
                      disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="loading || !canInuse">
            <span x-show="!loading">Passa a In-use</span>
            <span x-show="loading" class="loading loading-spinner loading-sm"></span>
        </button>
    </form>

    {{-- Check-in --}}
    <form method="POST" action="{{ route('rentals.checkin', $rental) }}" data-phase="checked_in" x-on:submit.prevent="submit($el)">
        @csrf
        <button class="btn btn-accent btn-block shadow-none
                      !bg-accent !text-accent-content !border-accent
                      hover:brightness-95 focus-visible:outline-none focus-visible:ring focus-visible:ring-accent/30
                      disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="loading || !canCheckin">
            <span x-show="!loading">Check-in</span>
            <span x-show="loading" class="loading loading-spinner loading-sm"></span>
        </button>
    </form>

    {{-- Chiudi --}}
    <form method="POST" action="{{ route('rentals.close', $rental) }}" data-phase="closed" x-on:submit.prevent="submit($el)">
        @csrf
        <button class="btn btn-success btn-block shadow-none
                      !bg-success !text-success-content !border-success
                      hover:brightness-95 focus-visible:outline-none focus-visible:ring focus-visible:ring-success/30
                      disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="loading || !canClose">
            <span x-show="!loading">Chiudi</span>
            <span x-show="loading" class="loading loading-spinner loading-sm"></span>
        </button>
    </form>

    {{-- Cancella --}}
    <form method="POST" action="{{ route('rentals.cancel', $rental) }}" data-phase="cancelled" x-on:submit.prevent="submit($el)">
        @csrf
        <button class="btn btn-error btn-block shadow-none
                      !bg-error !text-error-content !border-error
                      hover:brightness-95 focus-visible:outline-none focus-visible:ring focus-visible:ring-error/30
                      disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="loading || !canCancel">
            <span x-show="!loading">Cancella</span>
            <span x-show="loading" class="loading loading-spinner loading-sm"></span>
        </button>
    </form>
</div>

{{-- Modale pagamento --}}
<div
    x-data="paymentModal(
        '{{ route('rentals.record_payment', $rental) }}',
        {{ (float)($rental->final_amount_override ?? $rental->amount) }},
        {
            hasBasePayment: @js($rental->has_base_payment),
            basePaid: @js((float) $rental->base_paid_total),
            hasCombinedPayment: @js($rental->has_combined_payment),
            hasOveragePayment: @js($rental->has_distance_overage_payment),
            // ✅ Totale acconti già PAGATI (serve per calcolare il residuo quota base)
            accontoPaid: {{ (float) $rental->charges()->where('kind','acconto')->where('payment_recorded', true)->sum('amount') }},

            kinds: [
                {val:'base',              label:'Quota base / saldo'},
                {val:'distance_overage',  label:'Km extra'},
                {val:'base+distance_overage', label:'Quota base + Km extra'},
                {val:'damage',            label:'Danni'},
                {val:'surcharge',         label:'Sovrapprezzo'},
                {val:'fine',              label:'Multe'},
                {val:'acconto',          label:'Acconto'},
                {val:'other',             label:'Altro'},
            ],
        }
    )"
    x-init="init()"
    x-on:open-payment-modal.window="openModal($event.detail)"
>
    <template x-teleport="body">
        <div x-show="open" x-transition.opacity class="fixed inset-0 z-[95] flex items-center justify-center bg-black/50 px-4"
             role="dialog" aria-modal="true" aria-labelledby="payment-modal-title" @keydown.escape.prevent.stop="close()">
            <div class="absolute inset-0" @click="close()"></div>

            <div x-show="open" x-transition.scale
                class="relative bg-base-100 rounded-lg shadow-xl w-full sm:max-w-lg max-h-[85vh] flex flex-col">

              <!-- Header -->
              <div class="px-6 pt-6 pb-3 sticky top-0 bg-base-100/95 backdrop-blur supports-[backdrop-filter]:bg-base-100/80">
                <h2 id="payment-modal-title" class="text-lg font-semibold">Registra Pagamento</h2>
                <button type="button" class="absolute right-2 top-2 btn btn-ghost btn-xs" @click="close()">✕</button>
              </div>

              <!-- Body (scrollable) -->
              <div class="px-6 pb-2 overflow-y-auto">
                <form x-on:submit.prevent="submit()" class="space-y-4">
                  @csrf
                  <!-- Tipo -->
                  <div>
                    <label for="payment-kind" class="label"><span class="label-text">Tipo</span></label>
                    <select id="payment-kind" x-model="kind" name="kind"
                            class="mt-1 w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm appearance-none pr-8 focus:border-indigo-500 focus:ring-indigo-500" required
                            @change="onKindChange()">
                      <option value="" disabled>Seleziona tipo</option>
                      <template x-for="k in kinds" :key="k.val">
                        <option :value="k.val" x-text="k.label"></option>
                      </template>
                    </select>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-show="kind === 'other'">
                        Per un saldo o un versamento aggiuntivo del noleggio scegli Quota base / saldo.
                    </p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-show="kind"
                        x-text="@js($rental->assignment_id !== null) && @js(\App\Models\RentalCharge::COMMISSIONABLE_KINDS).includes(kind)
                            ? 'Questo pagamento entra nella base delle commissioni.'
                            : 'Questo pagamento è escluso dalla base delle commissioni.'"></p>
                  </div>

                  <!-- Importo -->
                  <div>
                    <label for="payment-amount" class="label"><span class="label-text">Importo</span></label>
                    <input id="payment-amount" type="number" step="0.01" min="0.01" max="9999999999.99" x-model.number="amount" name="amount"
                          class="block w-full rounded-md border border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 px-3 py-2 text-sm" required>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-show="hasCombinedPayment && (kind === 'base' || kind === 'distance_overage' || kind === 'base+distance_overage')">
                        Sono presenti pagamenti che comprendono anche i km extra. Verifica il saldo e inserisci l'importo da incassare.
                    </p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" x-show="hasOveragePayment && !hasCombinedPayment && (kind === 'distance_overage' || kind === 'base+distance_overage')">
                        Sono già registrati pagamenti per i km extra. Verifica lo storico e inserisci solo l'eventuale importo aggiuntivo.
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-300 mt-1" x-show="kind === 'distance_overage' && distanceOverageDue > 0 && !hasOveragePayment">
                      Valore precompilato dai km extra.
                    </p>
                  </div>

                  <!-- Metodo -->
                  <div>
                    <label for="payment-method" class="label"><span class="label-text">Metodo di Pagamento</span></label>
                    <select id="payment-method" x-model="payment_method" name="payment_method"
                            class="mt-1 w-full rounded-md border-gray-300 bg-white text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm pr-8 focus:border-indigo-500 focus:ring-indigo-500" required>
                      <option value="" disabled>Seleziona metodo</option>
                      <option value="cash">Contanti</option>
                      <option value="pos">Carta di Credito</option>
                      <option value="bank_transfer">Bonifico Bancario</option>
                      <option value="other">Altro</option>
                    </select>
                  </div>

                  <!-- Note / Riferimento -->
                  <div>
                    <label for="payment-notes" class="label"><span class="label-text">Note</span></label>
                    <textarea id="payment-notes" x-model.trim="payment_notes" name="payment_notes" rows="3" maxlength="255"
                              class="block w-full rounded-md border bg-white text-gray-900 dark:bg-gray-900 dark:text-gray-100 px-3 py-2 text-sm"></textarea>
                  </div>
                  <div>
                    <label for="payment-reference" class="label"><span class="label-text">Riferimento</span></label>
                    <input id="payment-reference" type="text" x-model.trim="payment_reference" name="payment_reference" maxlength="255"
                          class="block w-full rounded-md border bg-white text-gray-900 dark:bg-gray-900 dark:text-gray-100 px-3 py-2 text-sm">
                  </div>
                </form>
              </div>

              <!-- Footer (sticky) -->
              <div class="px-6 py-4 border-t sticky bottom-0 bg-base-100/95 backdrop-blur supports-[backdrop-filter]:bg-base-100/80">
                <div class="flex justify-end gap-3">
                  <button type="button" class="btn btn-neutral px-2" @click="close()" :disabled="loading">Annulla</button>
                  <button type="button" class="btn btn-primary px-2" @click="submit()" :disabled="loading">
                    <span x-show="!loading">Registra</span>
                    <span x-show="loading" class="loading loading-spinner loading-sm"></span>
                  </button>
                </div>
              </div>
            </div>

        </div>
    </template>
</div>

{{-- Alpine helpers --}}
<script>
document.addEventListener('alpine:init', () => {

  // ===== ACTIONS =====
  Alpine.data('rentalActions', (initial) => ({
    loading: false,
    phase: initial.phase,
    flags: {
      hasBasePayment:    !!(initial.flags?.hasBasePayment),
      hasOveragePayment: !!(initial.flags?.hasOveragePayment),
    },
    accontoPaid: Number(initial.accontoPaid || 0),
    overageUrl: initial.overageUrl || null,
    overage: { ready:false, amount:0, cents:0, km:0 },

    // Label fasi
    phaseLabels: {
      checked_out:'Check-out', in_use:'In use', checked_in:'Check-in',
      closed:'Chiuso', cancelled:'Annullato', canceled:'Annullato',
      no_show:'No-show', draft:'Bozza', reserved:'Prenotato',
    },
    phaseLabel(k){ return this.phaseLabels[k] ?? k.replace(/_/g,' ') },

    // Abilitazioni bottoni
    get canInuse(){    return ['draft', 'checked_out', 'reserved'].includes(this.phase) },
    get canCheckin(){  return ['checked_out','in_use'].includes(this.phase) },
    get canClose(){
      if (this.phase !== 'checked_in') return false;

      // ✅ Blocco chiusura se manca pagamento base (nuova richiesta)
      if (!this.flags.hasBasePayment) return false;

      // ✅ Se ci sono km extra dovuti, serve pagamento km extra
      const needOverage = this.overage.ready && Number(this.overage.amount) > 0;
      return !needOverage || !!this.flags.hasOveragePayment;
    },
    get canCancel(){ return ['draft','reserved'].includes(this.phase) },

    async init(){
      if (this.overageUrl) await this.fetchOverage();

      // Aggiorna flag dalla risposta del backend (es. dopo salvataggio pagamento)
      window.addEventListener('rental-flags-updated', (e) => {
        const f = e.detail || {};
        if ('has_base_payment' in f)               this.flags.hasBasePayment    = !!f.has_base_payment;
        if ('has_distance_overage_payment' in f)   this.flags.hasOveragePayment = !!f.has_distance_overage_payment;
        if ('has_distance_overage_payment' in f) window.__hasDistanceOveragePayment = !!f.has_distance_overage_payment;
        if ('acconto_paid_total' in f) this.accontoPaid = Number(f.acconto_paid_total || 0);
      });
    },

    async submit(formEl){
      if (this.loading) return;
      const phase = formEl.dataset.phase || null;
      const msg = phase ? `Vuoi passare alla fase ${this.phaseLabel(phase)}?` : 'Confermi l’operazione?';
      if (!confirm(msg)) return;

      this.loading = true;
      try {
        const token = formEl.querySelector('input[name=_token]')?.value
                   || document.querySelector('meta[name=csrf-token]')?.content;

        const res = await fetch(formEl.action, {
          method:'POST',
          headers:{ 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json', 'X-CSRF-TOKEN': token },
          body: new FormData(formEl),
          credentials:'same-origin'
        });

        let data=null; try{ data=await res.clone().json(); }catch(_){}
        console.log('[STATE]', { oldPhase: this.phase, newPhase: data?.status, flags: data?.flags });

        if (!res.ok || data?.ok===false) {
          const msg = (data && (data.message||data.error)) || 'Operazione non riuscita.';
          window.dispatchEvent(new CustomEvent('toast', { detail:{type:'error', message:msg} }));
          return;
        }

        if (data?.status) {
          this.phase = data.status;
          window.Livewire?.dispatch('rental-state-updated', {rentalId: @js((int) $rental->id)});
        }
        if (data?.flags) {
          if ('has_base_payment' in data.flags)
            this.flags.hasBasePayment = !!data.flags.has_base_payment;
          if ('has_distance_overage_payment' in data.flags)
            this.flags.hasOveragePayment = !!data.flags.has_distance_overage_payment;
        }

        // ricalcola l’overage per aggiornare badge/bottoni
        if (this.overageUrl) await this.fetchOverage();

        window.dispatchEvent(new CustomEvent('toast', {
          detail:{type:'success', message:`Stato aggiornato${data?.status?`: ${data.status}`:''}`}
        }));
      } finally {
        this.loading = false;
      }
    },

    async fetchOverage(){
      try {
        const res  = await fetch(this.overageUrl, { headers:{ 'Accept':'application/json' } });
        const data = await res.json();

        if (!data.ok || !data.has_data) {
          // ✅ reset completo, includo anche km
          this.overage = { ready:true, amount:0, cents:0, km:0 };
          window.__distanceOverageDue = 0;
        } else {
          const amt = Number(data.amount || 0);

          // ✅ Leggo i km extra dal backend (retro-compatibile)
          const kmExtra = Number(
            data.km_extra ?? data.extra_km ?? data.km_over ?? data.over_km ?? data.km ?? 0
          );

          // ✅ Salvo anche km nella state Alpine
          this.overage = {
            ready: true,
            amount: amt,
            cents: Number(data.cents || 0),
            km: kmExtra,
          };

          window.__distanceOverageDue = amt;
        }

        // Fonte backend per "già pagato"
        if (typeof data.has_payment !== 'undefined') {
          this.flags.hasOveragePayment = !!data.has_payment;
          window.__hasDistanceOveragePayment = this.flags.hasOveragePayment;
        }
      } catch(_) {
        // ✅ reset completo anche in errore
        this.overage = { ready:true, amount:0, cents:0, km:0 };
        window.__distanceOverageDue = 0;
      }
    },

    /**
     * ✅ Testo badge km extra: importo + km (se presenti)
     * Nota: uso concatenazione stringhe per evitare problemi di parsing con i backtick.
     */
    formatOverageBadge(){
      var amt = this.formatEuro(this.overage.amount);
      var km  = Number(this.overage.km || 0);

      // Se il backend non fornisce i km, mostro solo l’importo (comportamento attuale)
      if (km <= 0) return amt;

      return amt + ' (+' + km.toLocaleString('it-IT') + ' km)';
    },

    formatEuro(v){ return Number(v??0).toLocaleString('it-IT',{style:'currency',currency:'EUR'}) },
  }));

  // ===== MODALE PAGAMENTO =====
  // Supporta: paymentModal(url)  oppure  paymentModal(url, defaultAmount, {kinds:[...]} )
  Alpine.data('paymentModal', (arg1, arg2 = 0, arg3 = null) => {
    const isString     = typeof arg1 === 'string';
    const actionUrl    = isString ? arg1 : (arg1?.actionUrl ?? '');
    let baseAmount = isString ? Number(arg2 ?? 0) : Number(arg1?.defaultAmount ?? 0);

    const defaultKinds = [
      {val:'base',             label:'Quota base / saldo'},
      {val:'distance_overage', label:'Km extra'},
      {val:'base+distance_overage', label:'Quota base + Km extra'},
      {val:'damage',           label:'Danni'},
      {val:'surcharge',        label:'Sovrapprezzo'},
      {val:'fine',             label:'Multe'},
      {val:'acconto',          label:'Acconto'},
      {val:'other',            label:'Altro'},
    ];
    const kindsFromArgs = Array.isArray(arg3?.kinds) ? arg3.kinds
                        : (Array.isArray(arg1?.kinds) ? arg1.kinds : null);

    const initialHasBasePayment =
      !!(arg3?.hasBasePayment ?? arg1?.hasBasePayment ?? false);

    const initialAccontoPaid =
      Number(arg3?.accontoPaid ?? arg1?.accontoPaid ?? 0);

    return {
      open:false,
      loading:false,
      amount: 0,
      kind:'',
      payment_method:'',
      payment_notes:'',
      payment_reference:'',
      description:'', // retro-compat
      requestKey: null,
      basePaid: Number(arg3?.basePaid ?? arg1?.basePaid ?? 0),
      hasCombinedPayment: !!(arg3?.hasCombinedPayment ?? arg1?.hasCombinedPayment ?? false),
      hasOveragePayment: !!(arg3?.hasOveragePayment ?? arg1?.hasOveragePayment ?? false),
      accontoPaid: initialAccontoPaid,
      kindsAll: kindsFromArgs || defaultKinds,
      kinds: [],
      hasBasePayment: initialHasBasePayment,
      distanceOverageDue: 0,
      baseAmount: Number(baseAmount || 0),

      /**
      * ✅ Ritorna SEMPRE e SOLO l'importo dei km extra dovuti.
      * Fonte preferita: window.__distanceOverageDue (settata da rentalActions.fetchOverage()).
      * Fallback: distanceOverageDue locale.
      */
      getOverageDue() {
        const w = Number(window.__distanceOverageDue || 0);
        if (w > 0) return w;

        const local = Number(this.distanceOverageDue || 0);
        return local > 0 ? local : 0;
      },

      /**
      * Residuo quota base: sottrae tutti i versamenti della quota base e gli acconti.
      */
      baseDue(){
        if (this.hasCombinedPayment) return null;
        const base = Number(this.baseAmount || 0);
        const acc  = Number(this.basePaid || 0);
        return Math.max(0, +(base - acc).toFixed(2));
      },

      /**
      * ✅ Residuo "quota base + km extra" = (base - acconto) + (solo km extra).
      */
      basePlusOverageDue(){
        if (this.baseDue() === null || this.hasOveragePayment) return null;
        const over = Number(this.getOverageDue() || 0);
        return Math.max(0, +(this.baseDue() + over).toFixed(2));
      },

      applyKindsFilter(){
        const over = Number(this.getOverageDue() || 0);

        this.kinds = this.kindsAll.filter(k => {
          // ✅ mostra km extra SOLO se > 0
          if (k.val === 'distance_overage') return over > 0;

          // ✅ mostra base+distance_overage SOLO se > 0
          if (k.val === 'base+distance_overage') return over > 0;

          return true;
        });

        if (over <= 0 && (this.kind === 'distance_overage' || this.kind === 'base+distance_overage')) {
          this.kind = '';
        }
      },

      init(){
        // filtro iniziale
        this.applyKindsFilter();

        window.addEventListener('rental-flags-updated', (e) => {
          const f = e.detail || {};
          if ('base_paid_total' in f) this.basePaid = Number(f.base_paid_total);
          if ('has_base_payment' in f) this.hasBasePayment = !!f.has_base_payment;
          if ('has_combined_payment' in f) this.hasCombinedPayment = !!f.has_combined_payment;
          if ('has_distance_overage_payment' in f) {
            this.hasOveragePayment = !!f.has_distance_overage_payment;
            window.__hasDistanceOveragePayment = this.hasOveragePayment;
          }
          if ('acconto_paid_total' in f) this.accontoPaid = Number(f.acconto_paid_total);
          if (this.open && 'base_paid_total' in f && !this.loading) this.onKindChange();
        });

        // ascolta aggiornamenti da backend (dopo storePayment)
        window.addEventListener('rental-amount-updated', (e) => {
          const d = e.detail || {};

          // ✅ nuovo importo base aggiornato (post-override)
          if (typeof d.base_amount !== 'undefined') {
            this.baseAmount = Number(d.base_amount || 0);

            // Se sto pagando la quota base (o base+overage), aggiorno la precompilazione
            if (this.open && this.kind === 'base') {
              this.amount = this.baseDue() ?? '';
            }
            if (this.open && this.kind === 'base+distance_overage') {
              this.amount = this.basePlusOverageDue() ?? '';
            }
          }
        });
      },

      openModal(detail = null){
        this.requestKey = null;
        // reset pulito ad ogni apertura
        this.kind = '';
        this.amount = 0;

        // ✅ riallineo SEMPRE l'overage dovuto (solo km extra)
        // Se detail lo passa, lo uso, altrimenti prendo window.
        const over = (detail && typeof detail.distanceOverage !== 'undefined')
          ? Number(detail.distanceOverage || 0)
          : Number(window.__distanceOverageDue || 0);

        this.distanceOverageDue = over > 0 ? over : 0;

        this.open = true;
        this.applyKindsFilter();

        const alreadyPaid = this.hasOveragePayment || !!window.__hasDistanceOveragePayment;

        /**
        * Default suggerito:
        * - se ci sono km extra e NON risultano pagati -> precompilo "distance_overage"
        * - altrimenti -> "base" (se disponibile)
        */
        if (this.getOverageDue() > 0 && !alreadyPaid) {
          this.kind = 'distance_overage';
          this.amount = Number(this.getOverageDue()).toFixed(2);
          return;
        }

        this.kind = 'base';
        this.amount = this.baseDue() ?? '';
      },

      close(){ this.open=false; this.loading=false; },

      onKindChange(){
        if (this.kind === 'distance_overage') {
          if (this.hasOveragePayment) { this.amount = ''; return; }
          const due = Number(this.getOverageDue() || 0);
          this.amount = due > 0 ? Number(due.toFixed(2)) : 0;
          return;
        }

        if (this.kind === 'base') {
          this.amount = this.baseDue() ?? '';
          return;
        }

        if (this.kind === 'base+distance_overage') {
          this.amount = this.basePlusOverageDue() ?? '';
          return;
        }

        this.amount = 0;
      },

      async submit(){
        if (this.loading) return;
        this.loading = true;
        try{
          if (!this.requestKey) {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            bytes[6] = (bytes[6] & 15) | 64;
            bytes[8] = (bytes[8] & 63) | 128;
            const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
            this.requestKey = `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
          }
          const fd = new FormData();
          fd.append('request_key', this.requestKey);
          fd.append('kind', this.kind);
          fd.append('amount', this.amount);
          fd.append('payment_method', this.payment_method);
          const notes = this.payment_notes || this.description || '';
          if (notes) fd.append('payment_notes', notes);
          if (this.payment_reference) fd.append('payment_reference', this.payment_reference);

          const token = document.querySelector('meta[name=csrf-token]')?.content;
          const res = await fetch(actionUrl, {
            method:'POST',
            headers:{ 'X-Requested-With':'XMLHttpRequest','Accept':'application/json','X-CSRF-TOKEN':token },
            body: fd,
            credentials:'same-origin'
          });

          let data=null; try{ data=await res.clone().json(); }catch(_){}

          if (!res.ok || data?.ok===false) {
            const msg = (data && (data.message||data.error)) || 'Operazione non riuscita.';
            window.dispatchEvent(new CustomEvent('toast', { detail:{type:'error', message:msg} }));
            this.loading=false; return;
          }

          window.dispatchEvent(new CustomEvent('toast', { detail:{type:'success', message:'Pagamento registrato.'} }));

          // Aggiorna i flag in pagina (sicuro anche senza refresh)
          if (data?.flags) window.dispatchEvent(new CustomEvent('rental-flags-updated', { detail: data.flags }));
          // passa anche il totale acconti pagati ai listener della pagina (badge in alto)
          if (typeof data?.acconto_paid_total !== 'undefined') {
            window.dispatchEvent(new CustomEvent('rental-flags-updated', {
              detail: { acconto_paid_total: Number(data.acconto_paid_total || 0) }
            }));
          }

          /**
           * Aggiorna totale acconti pagati lato UI.
           * Nota: accontoPaid iniziale arriva da Blade, ma dopo un pagamento non cambia
           * finché non lo aggiorniamo con la risposta del backend.
           */
          if (typeof data?.acconto_paid_total !== 'undefined') {
            this.accontoPaid = Number(data.acconto_paid_total || 0);
          }
          this.basePaid = Number(data.base_paid_total ?? this.basePaid);
          this.hasCombinedPayment = !!(data.has_combined_payment ?? this.hasCombinedPayment);
          this.hasBasePayment = !!(data.flags?.has_base_payment ?? this.hasBasePayment);
          this.hasOveragePayment = !!(data.flags?.has_distance_overage_payment ?? this.hasOveragePayment);

          /**
           * Se l’utente sta registrando un pagamento "base" o "base+km extra",
           * riallineiamo l’importo precompilato ai nuovi dati (base - acconto).
           */
          if (this.kind === 'base') {
            this.amount = Number(this.baseDue() || 0);
          }
          if (this.kind === 'base+distance_overage') {
            this.amount = Number(this.basePlusOverageDue() || 0);
          }
          if (this.kind === 'distance_overage') {
            window.dispatchEvent(new CustomEvent('rental-flags-updated', {
              detail: { has_distance_overage_payment: true }
            }));
          }

          window.Livewire?.dispatch('rental-payment-recorded', {rentalId: @js((int) $rental->id)});

          // reset soft
          this.close();
          this.kind=''; this.payment_method=''; this.payment_notes=''; this.payment_reference='';
          this.description=''; this.amount = Number(this.baseAmount || 0);
          this.requestKey = null;
        } catch (_) {
          window.dispatchEvent(new CustomEvent('toast', {detail: {type:'error', message:'Connessione interrotta. Controlla lo storico prima di riprovare.'}}));
        } finally { this.loading=false; }
      },
    };
  });

});
</script>
