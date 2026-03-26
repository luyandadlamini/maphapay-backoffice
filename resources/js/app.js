import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

// Hardware Wallet Service (Ledger/Trezor integration)
// Lazy loaded - dependencies only loaded when hardware wallet features are used
import './services/hardware-wallet';
