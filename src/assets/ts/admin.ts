// Entry point of the administration: the reservation search of unmatched payments (Payments page) and the
// live preview of the tables for sale (Stoly v prodeji).

import { initReservationSearch } from './admin/reservation-search';
import { initTableAvailability } from './admin/table-availability';

initReservationSearch();
initTableAvailability();