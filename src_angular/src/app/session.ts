import { inject, Injectable, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { Api, Session } from './api';

@Injectable({ providedIn: 'root' })
export class SessionService {
  private readonly api = inject(Api);
  private readonly current = signal<Session | null>(null);
  private generation = 0;
  readonly session = this.current.asReadonly();

  load(): Observable<Session> {
    const generation = ++this.generation;
    this.current.set(null);
    return this.api.session().pipe(tap((session) => {
      if (this.generation === generation) this.current.set(session);
    }));
  }

  clear(): void {
    this.generation++;
    this.current.set(null);
  }
}
