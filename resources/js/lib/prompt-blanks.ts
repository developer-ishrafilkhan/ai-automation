/** Same rule as App\Support\PromptBlanks: leftover [FILL-IN] blanks from ready-made prompts. */
const PATTERN = /\[(?:[^[\]\n]*[A-Z]{2,}[^[\]\n]*|e\.g\.[^[\]\n]*|[^[\]\n\s]+(?:\s*\/\s*[^[\]\n\s]+)+)\](?!\()/g;

export function promptBlanks(text: string | undefined): string[] {
    return [...new Set(text?.match(PATTERN) ?? [])];
}
