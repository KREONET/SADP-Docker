let messages = {};

export function loadTranslations(container) {
  const source = container.querySelector('[data-canvas-translations]');
  messages = source ? JSON.parse(source.textContent) : {};
}

export function t(key, replacements = {}) {
  const message = Object.hasOwn(messages, key) ? messages[key] : key;
  return message.replace(/:([a-z_][a-z0-9_]*)/gi, (token, name) =>
    Object.hasOwn(replacements, name) ? replacements[name] : token);
}
