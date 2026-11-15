import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { SessionService } from './session';

export const sessionInterceptor: HttpInterceptorFn = (request, next) => {
  const router = inject(Router);
  const session = inject(SessionService);
  return next(request).pipe(
    catchError((error: unknown) => {
      if (error instanceof HttpErrorResponse && error.status === 401) {
        session.clear();
        if (!request.url.endsWith('/login')) {
          void router.navigate(['/connexion'], { queryParams: { session: 'expiree' } });
        }
      }
      return throwError(() => error);
    }),
  );
};
