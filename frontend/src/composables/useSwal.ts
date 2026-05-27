import { reactive } from 'vue'

interface ConfirmState {
  open:    boolean
  title:   string
  text:    string
  resolve: ((confirmed: boolean) => void) | null
}

interface SnackState {
  open:    boolean
  title:   string
  text:    string
  color:   string
  timeout: number
}

export const _confirmState = reactive<ConfirmState>({
  open: false, title: '', text: '', resolve: null,
})

export const _snackState = reactive<SnackState>({
  open: false, title: '', text: '', color: 'success', timeout: 3000,
})

export function useSwal() {
  function confirmar(title: string, text = ''): Promise<{ isConfirmed: boolean }> {
    _confirmState.title = title
    _confirmState.text  = text
    _confirmState.open  = true
    return new Promise(resolve => {
      _confirmState.resolve = (val) => resolve({ isConfirmed: val })
    })
  }

  function exito(title: string, text = '', timeout = 3500) {
    Object.assign(_snackState, { open: true, title, text, color: 'success', timeout })
  }

  function error(title: string, text = '') {
    Object.assign(_snackState, { open: true, title, text, color: 'error', timeout: 5000 })
  }

  return { confirmar, exito, error }
}
