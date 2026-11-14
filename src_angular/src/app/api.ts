import { DOCUMENT } from '@angular/common';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, map, Observable, of, throwError } from 'rxjs';

export const FIRST_YEAR = 2021;

export interface Draw {
  year: number;
  assignmentsByGiver: Record<string, string>;
  lines: string[];
}

interface ApiResponse<T> {
  data: T;
}

export function isMissingDraw(error: unknown): boolean {
  return error instanceof HttpErrorResponse &&
    error.status === 404 && error.error?.error?.code === 'draw_not_found';
}

@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = new URL('api/', inject(DOCUMENT).baseURI).pathname;

  login(login: string, password: string): Observable<void> {
    return this.http.post<ApiResponse<{ authenticated: boolean }>>(
      `${this.base}login`, { login, password }, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  logout(): Observable<void> {
    return this.http.post<ApiResponse<{ authenticated: boolean }>>(
      `${this.base}logout`, {}, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  draw(year: number): Observable<Draw> {
    return this.http.get<ApiResponse<Draw>>(
      `${this.base}draw/${year}`, { withCredentials: true },
    ).pipe(map((response) => response.data));
  }

  latestDraw(year: number): Observable<Draw | null> {
    if (year < FIRST_YEAR) {
      return of(null);
    }
    return this.draw(year).pipe(
      catchError((error: unknown) =>
        isMissingDraw(error) ? this.latestDraw(year - 1) : throwError(() => error),
      ),
    );
  }
}
