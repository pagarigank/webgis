import { Link } from 'react-router-dom';

const NotFoundPage: React.FC = () => (
  <div
    style={{
      minHeight: '60vh',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      fontFamily: 'system-ui, sans-serif',
    }}
  >
    <h1 style={{ margin: 0 }}>404</h1>
    <p style={{ color: '#555' }}>That page does not exist.</p>
    <Link to="/map" style={{ color: '#1a5fb4' }}>
      Go to the map
    </Link>
  </div>
);

export default NotFoundPage;
