import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { SessionService } from './session';
import { BACKGROUND_SESSION_REQUEST } from './api';

export const sessionInterceptor: HttpInterceptorFn = (request, next) => {
  const router = inject(Router);
  const session = inject(SessionService);
  return next(request).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401 && !request.context.get(BACKGROUND_SESSION_REQUEST)) {
        session.clear();
        if (!request.url.endsWith('/login')) {
          const current = router.getCurrentNavigation()?.finalUrl ?? router.parseUrl(router.url);
          const segments = current.root.children['primary']?.segments ?? [];
          const wishlist = segments.length === 1 && segments[0].path === 'wishlist';
          const share = segments.length === 1 && segments[0].path === 'partage';
          const target = current.queryParams['target'];
          void router.navigate(['/connexion'], { queryParams: {
            session: 'expiree',
            ...(wishlist ? { returnWishlist: '1' } : {}),
            ...(wishlist && typeof target === 'string' ? { target } : {}),
            ...(share ? { returnShare: '1' } : {}),
          } });
        }
      }
      return throwError(() => error);
    }),
  );
};
