// 1.4.41: betalt beløp ≠ ordretotal ved fullføring → «On hold» med notat.
// Webhooken er en stub av gatewayens (mu/v1441-test.php).
import { sjekk, ferdig, get, B } from '../lib/felles.mjs';

const ordre = () => get('/amendo-test/v1/kortordre', { headless: true });
const status = id => get(`/amendo-test/v1/kortordre-status&id=${id}`);
const webhook = async (id, verdi, success = 'true', eventCode = 'AUTHORISATION') => {
  const r = await fetch(B + '/aorder/v1/amendo-adyen', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ live: 'false', notificationItems: [{ NotificationRequestItem: {
      eventCode, success, merchantReference: `shop.test/${id}`, pspReference: `PSP${id}`,
      amount: { value: verdi, currency: 'NOK' },
    } }] }),
  });
  return r.status;
};

await get('/amendo-test/v1/setup');

console.log('webhook med riktig beløp');
let { id } = await ordre();
sjekk('webhook godtatt', (await webhook(id, 23530)) === 200);
let s = await status(id);
sjekk('23530 øre = 235,30 kr → «Behandler»', s.status === 'processing', s);
sjekk('… ingen beløpssikring-notat', !s.notater.some(n => n.includes('Beløpssikring')), s.notater);

console.log('\nwebhook med annet beløp');
({ id } = await ordre());
await webhook(id, 10000);
s = await status(id);
sjekk('100 kr betalt, total 235,30 → «On hold»', s.status === 'on-hold', s);
sjekk('… notat med begge beløpene', s.notater.some(n => n.includes('Beløpssikring') && n.includes('100,00') && n.includes('235,30')), s.notater);
sjekk('… transaksjonen er lagret', s.transaksjon === `PSP${id}`, s);

console.log('\nwebhook uten betaling');
({ id } = await ordre());
await webhook(id, 23530, 'false');
s = await status(id);
sjekk('success: false → fortsatt «Venter på betaling»', s.status === 'pending', s);
({ id } = await ordre());
await webhook(id, 10000, 'true', 'CANCELLATION');
sjekk('annet eventCode → urørt', (await status(id)).status === 'pending');

console.log('\npayment_complete() uten webhook');
({ id } = await ordre());
const d = await get('/amendo-test/v1/betal-direkte', { id });
sjekk('ingen beløp fra Adyen → «Behandler» som før', d.status === 'processing', d);

console.log('\nflere ordrer i samme webhook');
const a = (await ordre()).id, b = (await ordre()).id;
await fetch(B + '/aorder/v1/amendo-adyen', {
  method: 'POST', headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ notificationItems: [a, b].map((x, i) => ({ NotificationRequestItem: {
    eventCode: 'AUTHORISATION', success: 'true', merchantReference: `shop.test/${x}`, pspReference: `P${x}`,
    amount: { value: i === 0 ? 23530 : 999, currency: 'NOK' },
  } })) }),
});
sjekk('riktig beløp på den ene → «Behandler»', (await status(a)).status === 'processing');
sjekk('feil beløp på den andre → «On hold»', (await status(b)).status === 'on-hold');

ferdig();
