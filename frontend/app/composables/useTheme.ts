export function useTheme() {
  const dark = useState('theme-dark', () => false)

  function sync(): void {
    dark.value = document.documentElement.classList.contains('dark')
  }

  function toggle(): void {
    dark.value = !dark.value
    document.documentElement.classList.toggle('dark', dark.value)
    try {
      localStorage.setItem('equo-theme', dark.value ? 'dark' : 'light')
    }
    catch {
      // The selected theme still applies for this page when storage is unavailable.
    }
  }

  return { dark, sync, toggle }
}
