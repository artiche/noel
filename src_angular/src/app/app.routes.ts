import { Routes } from '@angular/router';
export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'tirages' },
  { path: 'connexion', loadComponent: () => import('./login').then((module) => module.Login) },
  { path: 'tirages', loadComponent: () => import('./draws').then((module) => module.Draws) },
  { path: 'tirages/:year', loadComponent: () => import('./draws').then((module) => module.Draws) },
  { path: '**', redirectTo: 'tirages' },
];
