import { DOCUMENT } from '@angular/common';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, map, Observable, of, throwError } from 'rxjs';
import type { Op } from 'quill';

export const FIRST_YEAR = 2021;

export interface DrawAssignment {
  giver: string;
  recipient: string;
  giverKey: string;
  recipientKey: string;
}

export interface Draw {
  year: number;
  assignmentsByGiver: Record<string, string>;
  lines: string[];
  assignments: DrawAssignment[];
}

export interface Session {
  authenticated: true;
  isAdmin: boolean;
  login: string;
  email: string;
  participant: string | null;
  participantKey: string | null;
}

export interface AdminUser {
  login: string;
  email: string;
  isAdmin: boolean;
}

export interface Participant {
  name: string;
  login: string | null;
  suggestedLogin: string;
  forbiddenRecipients: string[];
}

export interface AdminData {
  users: AdminUser[];
  participants: Participant[];
}

export interface Wishlist {
  participant: string;
  ops: Op[];
  revision: string;
  giver: { name: string; key: string } | null;
  recipient: { name: string; key: string; year: number } | null;
}

export interface RecipientWishlist {
  participant: string;
  year: number;
  ops: Op[];
}

export class InvalidNotificationResponseError extends Error {}

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

  session(): Observable<Session> {
    return this.http.get<ApiResponse<Session>>(
      `${this.base}session`, { withCredentials: true },
    ).pipe(map((response) => {
      if (response.data?.authenticated !== true || typeof response.data.isAdmin !== 'boolean' ||
          typeof response.data.login !== 'string' || typeof response.data.email !== 'string' ||
          !((response.data.participant === null && response.data.participantKey === null) ||
            (typeof response.data.participant === 'string' && typeof response.data.participantKey === 'string' &&
              response.data.participantKey !== ''))) {
        throw new Error('Invalid session response');
      }
      return response.data;
    }));
  }

  updateAccount(currentPassword: string, email?: string, newPassword?: string): Observable<void> {
    return this.http.post<ApiResponse<{ updated: boolean }>>(
      `${this.base}account`, { currentPassword, ...(email === undefined ? {} : { email }),
        ...(newPassword === undefined ? {} : { newPassword }) }, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  adminData(): Observable<AdminData> {
    return this.http.get<ApiResponse<AdminData>>(`${this.base}admin/users`, { withCredentials: true })
      .pipe(map((response) => response.data));
  }

  createUser(user: AdminUser & { participant?: string }): Observable<void> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}admin/users`, user, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  editUser(user: { login: string; email: string; isAdmin: boolean; password?: string }): Observable<void> {
    return this.http.patch<ApiResponse<unknown>>(`${this.base}admin/users`, user, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  deleteUser(login: string): Observable<void> {
    return this.http.delete<ApiResponse<unknown>>(`${this.base}admin/users`, { body: { login }, withCredentials: true })
      .pipe(map(() => undefined));
  }

  adminReset(login: string): Observable<void> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}admin/users/reset`, { login }, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  createParticipant(name: string): Observable<void> {
    return this.http.post<ApiResponse<unknown>>(`${this.base}admin/participants`, { name }, { withCredentials: true })
      .pipe(map(() => undefined));
  }

  editParticipant(name: string, newName: string, forbiddenRecipients: string[]): Observable<void> {
    return this.http.patch<ApiResponse<unknown>>(`${this.base}admin/participants`,
      { name, newName, forbiddenRecipients }, { withCredentials: true }).pipe(map(() => undefined));
  }

  deleteParticipant(name: string): Observable<void> {
    return this.http.delete<ApiResponse<unknown>>(`${this.base}admin/participants`, { body: { name }, withCredentials: true })
      .pipe(map(() => undefined));
  }

  requestPasswordReset(login: string): Observable<void> {
    return this.http.post<ApiResponse<{ passwordResetRequested: boolean }>>(
      `${this.base}password/forgot`, { login }, { withCredentials: true },
    ).pipe(map(() => undefined));
  }

  wishlist(): Observable<Wishlist> {
    return this.http.get<ApiResponse<Wishlist>>(`${this.base}wishlist`, { withCredentials: true })
      .pipe(map((response) => {
        const giver = response.data?.giver;
        if (giver !== null && (!giver || typeof giver.name !== 'string' || giver.name === '' ||
            typeof giver.key !== 'string' || giver.key === '')) {
          throw new Error('Invalid wishlist giver');
        }
        return response.data;
      }));
  }

  saveWishlist(ops: Op[], revision: string): Observable<{ revision: string }> {
    return this.http.put<ApiResponse<{ revision: string }>>(
      `${this.base}wishlist`, { ops, revision }, { withCredentials: true },
    ).pipe(map((response) => response.data));
  }

  recipientWishlist(target: string): Observable<RecipientWishlist> {
    return this.http.get<ApiResponse<RecipientWishlist>>(
      `${this.base}wishlist/recipient`, { params: { target }, withCredentials: true },
    ).pipe(map((response) => response.data));
  }

  notifyGiver(expectedGiver: string): Observable<string> {
    return this.http.post<ApiResponse<{ sent: boolean; giver: string }>>(
      `${this.base}wishlist/notify`, { expectedGiver }, { withCredentials: true },
    ).pipe(map((response) => {
      if (response.data?.sent !== true || typeof response.data.giver !== 'string' || response.data.giver === '') {
        throw new InvalidNotificationResponseError('Invalid wishlist notification response');
      }
      return response.data.giver;
    }));
  }

  draw(year: number): Observable<Draw> {
    return this.http.get<ApiResponse<Draw>>(
      `${this.base}draw/${year}`, { withCredentials: true },
    ).pipe(map((response) => {
      const draw = response.data;
      if (!draw || !Array.isArray(draw.assignments) ||
          !draw.assignments.every((assignment) => assignment !== null && typeof assignment === 'object' &&
            typeof assignment.giver === 'string' && typeof assignment.recipient === 'string' &&
            typeof assignment.giverKey === 'string' && assignment.giverKey !== '' &&
            typeof assignment.recipientKey === 'string' && assignment.recipientKey !== '')) {
        throw new Error('Invalid draw assignments');
      }
      return draw;
    }));
  }

  latestDraw(year: number, minimumYear = FIRST_YEAR): Observable<Draw | null> {
    if (year < minimumYear) {
      return of(null);
    }
    return this.draw(year).pipe(
      catchError((error: unknown) =>
        isMissingDraw(error) ? this.latestDraw(year - 1, minimumYear) : throwError(() => error),
      ),
    );
  }
}
