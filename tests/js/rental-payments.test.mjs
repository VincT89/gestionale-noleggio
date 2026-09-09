import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const template = readFileSync(fileURLToPath(new URL('../../resources/views/pages/rentals/partials/actions.blade.php', import.meta.url)), 'utf8');
const source = template.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replaceAll('@js((int) $rental->id)', '1');

function modal(options = {}, overage = 0) {
    const factories = {};
    const context = {
        Alpine: { data: (name, factory) => { factories[name] = factory; } },
        document: { addEventListener: (_event, listener) => listener() },
        window: { __distanceOverageDue: overage, __hasDistanceOveragePayment: false },
    };
    vm.runInNewContext(source, context);
    const payment = factories.paymentModal('/payments', 520, options);
    payment.openModal();
    return payment;
}

test('dopo 455 euro di quota base propone soltanto il saldo di 65 euro', () => {
    const payment = modal({basePaid:455, hasBasePayment:true});
    assert.equal(payment.kind, 'base');
    assert.equal(payment.amount, 65);
    assert.ok(payment.kinds.some(kind => kind.val === 'base'));
});

test('gli acconti compresi nel totale versato non vengono sottratti due volte', () => {
    assert.equal(modal({basePaid:455, accontoPaid:455}).amount, 65);
});

test('con la quota base interamente pagata non propone un altro incasso', () => {
    assert.equal(modal({basePaid:520}).amount, 0);
    assert.equal(modal({basePaid:600}).amount, 0);
});

test('un pagamento cumulativo precedente richiede la verifica manuale del saldo', () => {
    const payment = modal({hasCombinedPayment:true, hasOveragePayment:true}, 80);
    assert.equal(payment.kind, 'base');
    assert.equal(payment.amount, '');
    payment.kind = 'base+distance_overage';
    payment.onKindChange();
    assert.equal(payment.amount, '');
});

test('i km extra non ancora pagati vengono proposti separatamente dalla quota base', () => {
    const payment = modal({basePaid:455}, 80);
    assert.equal(payment.kind, 'distance_overage');
    assert.equal(Number(payment.amount), 80);
    payment.kind = 'base+distance_overage';
    payment.onKindChange();
    assert.equal(payment.amount, 145);
});

test('i km extra già pagati non vengono riproposti automaticamente', () => {
    const payment = modal({basePaid:455, hasOveragePayment:true}, 80);
    assert.equal(payment.kind, 'base');
    assert.equal(payment.amount, 65);
    payment.kind = 'distance_overage';
    payment.onKindChange();
    assert.equal(payment.amount, '');
    payment.kind = 'base+distance_overage';
    payment.onKindChange();
    assert.equal(payment.amount, '');
});
