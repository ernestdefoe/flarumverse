/** "en" → "English" in the reader's language; "mul" → the label for several languages. */
export default function languageName(code: string, locale: string, several: string): string {
  if (code === 'mul') return several;
  try {
    return new (Intl as any).DisplayNames([locale || 'en'], { type: 'language' }).of(code) || code;
  } catch (e) {
    return code;
  }
}
