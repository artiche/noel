import { Routes } from '@angular/router';
export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'tirages' },
  { path: 'connexion', loadComponent: () => import('./login').then((module) => module.Login) },
  { path: 'administration', loadComponent: () => import('./admin').then((module) => module.Admin) },
  { path: 'wishlist', loadComponent: () => import('./wishlist').then((module) => module.Wishlist),
    canDeactivate: [(component) => component.canDeactivate()] },
  { path: 'tirages', loadComponent: () => import('./draws').then((module) => module.Draws) },
  { path: 'tirages/:year', loadComponent: () => import('./draws').then((module) => module.Draws) },
  { path: '**', redirectTo: 'tirages' },
];
