import { Component, type ErrorInfo, type ReactNode } from 'react';

interface ErrorBoundaryProps {
  /** Shown instead of the crashed subtree. Keep it self-contained. */
  children: ReactNode;
  /** Short description of the region, e.g. "Technical description". */
  label?: string;
  /** Notified on capture; used by tests and telemetry. */
  onError?: (error: Error, info: ErrorInfo) => void;
}

interface ErrorBoundaryState {
  error: Error | null;
}

/**
 * Contains a render-time crash so one failing panel cannot take down the page
 * around it.
 *
 * The parcel editor mounts one independently-fetching tab at a time, and every
 * one of those tabs was previously able to white-screen the whole editor: a
 * single `undefined.length` in a tab's data path unmounted the navigation, the
 * workflow action bar, and the unsaved-changes guard with it, leaving the user
 * with a blank page and no way back except a browser reload that would discard
 * their edits. A boundary keeps the blast radius to the tab that actually
 * failed.
 *
 * The error is logged to the console as well as reported through `onError`:
 * React already logs the component stack, and losing the message entirely makes
 * a field-only failure very hard to diagnose.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error(
      `[ErrorBoundary]${this.props.label ? ` ${this.props.label}` : ''} crashed:`,
      error,
      info.componentStack,
    );
    this.props.onError?.(error, info);
  }

  /** Allows a parent to retry the subtree after the cause is resolved. */
  reset = (): void => {
    this.setState({ error: null });
  };

  render(): ReactNode {
    const { error } = this.state;
    if (!error) return this.props.children;

    return (
      <div className="card" data-testid="error-boundary-fallback" role="alert">
        <h2 className="card-title">
          {this.props.label ? `${this.props.label} could not be displayed` : 'This section could not be displayed'}
        </h2>
        <p className="text-sm text-gray-600">
          The rest of the page is still usable. Reload this section to try again.
        </p>
        <pre
          className="mt-3 overflow-x-auto rounded bg-gray-50 p-3 text-xs text-gray-700"
          data-testid="error-boundary-message"
        >
          {error.message}
        </pre>
        <div className="mt-4 flex gap-2">
          <button type="button" className="btn btn-sm" onClick={this.reset}>
            Try again
          </button>
        </div>
      </div>
    );
  }
}

export default ErrorBoundary;
