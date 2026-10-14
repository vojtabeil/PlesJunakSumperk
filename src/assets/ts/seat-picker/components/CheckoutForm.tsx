import { useRef, useState } from 'preact/hooks';
import type { Store } from '../store';

interface Props {
  store: Store;
  email: string;
  disabled: boolean;
}

type Field = 'email' | 'name' | 'phone' | 'consent';
type Errors = Partial<Record<Field, string>>;

const EmailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
/** Frequent typos of Czech e-mail domains -> the intended domain. */
const DomainTypos: Record<string, string> = {
  'gmial.com': 'gmail.com',
  'gmai.com': 'gmail.com',
  'gmail.cz': 'gmail.com',
  'gmal.com': 'gmail.com',
  'seznma.cz': 'seznam.cz',
  'sezam.cz': 'seznam.cz',
  'seznam.com': 'seznam.cz',
  'emial.cz': 'email.cz',
  'centum.cz': 'centrum.cz',
};

/** Error of one field, or undefined (the server checks everything again). */
export function validate(field: Field, value: string | boolean): string | undefined {
  if (field === 'email') {
    return EmailPattern.test(String(value).trim()) ? undefined : 'Zadejte e-mail ve tvaru jmeno@example.cz.';
  }
  if (field === 'name') {
    return String(value).trim().length >= 3 ? undefined : 'Vyplňte jméno a příjmení.';
  }
  if (field === 'phone') {
    const phone = String(value).trim();
    return phone === '' || /^\+?[0-9 ]{9,20}$/.test(phone) ? undefined : 'Telefon zadejte jen jako čísla, např. +420 777 123 456.';
  }
  return value === true ? undefined : 'Pro rezervaci je potřeba souhlas se zpracováním osobních údajů.';
}

/** "jana@gmial.com" -> "jana@gmail.com", otherwise null. */
export function emailSuggestion(email: string): string | null {
  const [local, domain] = email.trim().toLowerCase().split('@');
  const fixed = domain ? DomainTypos[domain] : undefined;
  return local && fixed ? `${local}@${fixed}` : null;
}

const Labels: Record<Field, string> = {
  email: 'E-mail',
  name: 'Jméno a příjmení',
  phone: 'Telefon',
  consent: 'Souhlas',
};

/**
 * E-mail, name and phone. Fields are checked when the visitor leaves them; on submit the errors
 * are listed at the top (with links to the fields). Typing keeps the chosen seats held.
 */
export function CheckoutForm({ store, email, disabled }: Props) {
  const form = useRef<HTMLFormElement>(null);
  const summary = useRef<HTMLDivElement>(null);
  const [errors, setErrors] = useState<Errors>({});
  const [listed, setListed] = useState<Errors>({});
  const [suggestion, setSuggestion] = useState<string | null>(null);

  const valueOf = (field: Field): string | boolean => {
    const input = form.current?.elements.namedItem(field) as HTMLInputElement | null;
    return field === 'consent' ? input?.checked === true : input?.value ?? '';
  };

  const check = (field: Field) => {
    const error = validate(field, valueOf(field));
    setErrors((current) => ({ ...current, [field]: error }));
    // A fixed field also disappears from the summary at the top.
    if (!error) {
      setListed((current) => {
        const { [field]: _fixed, ...rest } = current;
        return rest;
      });
    }
    if (field === 'email') {
      setSuggestion(emailSuggestion(String(valueOf('email'))));
    }
  };

  /** While typing, an error is only removed (never added) as soon as the value is right. */
  const onInput = (event: Event) => {
    store.keepAlive();
    const field = (event.target as HTMLInputElement).name as Field;
    if (errors[field] && !validate(field, valueOf(field))) {
      check(field);
    }
  };

  const submit = (event: SubmitEvent) => {
    event.preventDefault();
    const fields: Field[] = ['email', 'name', 'phone', 'consent'];
    const found: Errors = {};
    for (const field of fields) {
      const error = validate(field, valueOf(field));
      if (error) {
        found[field] = error;
      }
    }
    setErrors(found);
    setListed(found);
    if (Object.keys(found).length) {
      requestAnimationFrame(() => summary.current?.focus());
      return;
    }
    void store.confirm({
      email: String(valueOf('email')).trim(),
      name: String(valueOf('name')),
      phone: String(valueOf('phone')),
      consent: true,
    });
  };

  const useSuggestion = () => {
    const input = form.current?.elements.namedItem('email') as HTMLInputElement | null;
    if (input && suggestion) {
      input.value = suggestion;
      setSuggestion(null);
      check('email');
    }
  };

  const describedBy = (field: Field) => (errors[field] ? `${field}-error` : undefined);

  return (
    <form ref={form} id="checkout" class="confirm-form" noValidate onSubmit={submit} onInput={onInput}>
      <h3>Vaše údaje</h3>
      {Object.keys(listed).length > 0 && (
        <div ref={summary} class="error-summary" tabIndex={-1} role="alert">
          <p>Opravte prosím:</p>
          <ul>
            {(Object.keys(listed) as Field[]).map((field) => (
              <li key={field}>
                <a href={`#${field}`}>
                  {Labels[field]}: {listed[field]}
                </a>
              </li>
            ))}
          </ul>
        </div>
      )}

      <label for="email">E-mail</label>
      <span class="field-hint">Pošleme na něj potvrzení a platební údaje.</span>
      {errors.email && <span id="email-error" class="field-error">{errors.email}</span>}
      <input
        type="email"
        id="email"
        name="email"
        autoComplete="email"
        spellcheck={false}
        maxLength={255}
        defaultValue={email}
        aria-invalid={errors.email ? true : undefined}
        aria-describedby={describedBy('email')}
        onBlur={() => check('email')}
      />
      {suggestion && (
        <p class="field-hint">
          Nemysleli jste{' '}
          <button type="button" class="link-button" onClick={useSuggestion}>
            {suggestion}
          </button>
          ?
        </p>
      )}

      <label for="name">Jméno a příjmení</label>
      {errors.name && <span id="name-error" class="field-error">{errors.name}</span>}
      <input
        type="text"
        id="name"
        name="name"
        autoComplete="name"
        maxLength={255}
        aria-invalid={errors.name ? true : undefined}
        aria-describedby={describedBy('name')}
        onBlur={() => check('name')}
      />

      <label for="phone">
        Telefon <span class="optional">(nepovinné)</span>
      </label>
      {errors.phone && <span id="phone-error" class="field-error">{errors.phone}</span>}
      <input
        type="tel"
        id="phone"
        name="phone"
        autoComplete="tel"
        maxLength={20}
        aria-invalid={errors.phone ? true : undefined}
        aria-describedby={describedBy('phone')}
        onBlur={() => check('phone')}
      />

      {errors.consent && <span id="consent-error" class="field-error">{errors.consent}</span>}
      <label class="checkbox">
        <input type="checkbox" id="consent" name="consent" aria-describedby={describedBy('consent')} onChange={() => check('consent')} />
        Souhlasím se zpracováním osobních údajů pro účely rezervace.
      </label>
      <button type="submit" class="button button--primary" disabled={disabled}>
        Závazně rezervovat
      </button>
    </form>
  );
}
